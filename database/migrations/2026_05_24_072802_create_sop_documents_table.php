<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('sop_documents', function (Blueprint $table) {
            $table->id();
            $table->string('file_code', 20);      // SOP-001, SOP-002...
            $table->string('section_title');       // 章节标题
            $table->text('content');               // chunk 文字内容
            $table->jsonb('metadata')->nullable(); // 额外信息（版本号等）
            $table->timestamps();
        });

        // vector 栏位要用原生 SQL 加，Laravel schema builder 不支持
        DB::statement('ALTER TABLE sop_documents ADD COLUMN embedding vector(1536)');

        // HNSW 索引：让相似度搜索更快（Week 2 会用到）
        DB::statement('CREATE INDEX ON sop_documents USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sop_documents');
    }
};