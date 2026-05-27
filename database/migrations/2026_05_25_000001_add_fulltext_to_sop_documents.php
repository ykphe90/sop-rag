<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 加入 PostgreSQL 全文搜索支持
     *
     * content_tsv: tsvector 类型，储存预处理的全文索引
     * GIN Index:   倒排索引，让全文搜索极快
     * Trigger:     content 变动时自动更新 content_tsv
     */
    public function up(): void
    {
        // 1. 加入 tsvector 列
        DB::statement('ALTER TABLE sop_documents ADD COLUMN IF NOT EXISTS content_tsv tsvector');

        // 2. 建立 GIN 索引（全文搜索用）
        DB::statement('CREATE INDEX IF NOT EXISTS sop_documents_content_tsv_idx ON sop_documents USING gin(content_tsv)');

        // 3. 填充现有数据（用 simple 配置，适合中文）
        // 'simple': 不做词根化（stemming），直接用原始词，适合中文/专有名词
        DB::statement("
            UPDATE sop_documents
            SET content_tsv = to_tsvector('simple', coalesce(content, '') || ' ' || coalesce(section_title, ''))
        ");

        // 4. 建立 trigger，之后 INSERT/UPDATE 自动更新 content_tsv
        DB::statement("
            CREATE OR REPLACE FUNCTION sop_documents_tsv_trigger() RETURNS trigger AS $$
            BEGIN
                NEW.content_tsv := to_tsvector('simple',
                    coalesce(NEW.content, '') || ' ' || coalesce(NEW.section_title, '')
                );
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        ");

        DB::statement("
            DROP TRIGGER IF EXISTS sop_documents_tsv_update ON sop_documents
        ");

        DB::statement("
            CREATE TRIGGER sop_documents_tsv_update
            BEFORE INSERT OR UPDATE ON sop_documents
            FOR EACH ROW EXECUTE FUNCTION sop_documents_tsv_trigger()
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS sop_documents_tsv_update ON sop_documents');
        DB::statement('DROP FUNCTION IF EXISTS sop_documents_tsv_trigger()');
        DB::statement('DROP INDEX IF EXISTS sop_documents_content_tsv_idx');
        DB::statement('ALTER TABLE sop_documents DROP COLUMN IF EXISTS content_tsv');
    }
};
