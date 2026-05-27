<?php

namespace App\Console\Commands;

use App\Services\ChunkService;
use App\Services\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IndexSopDocuments extends Command
{
    // 命令名称：php artisan sop:index
    protected $signature = 'sop:index
                            {--fresh : 先清空数据库再重新索引}
                            {--file= : 只处理指定文件，例如 --file=SOP-001}';

    protected $description = '读取 SOP Markdown 文件，切块、向量化、存入数据库';

    public function __construct(
        private ChunkService    $chunker,
        private EmbeddingService $embedder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // --fresh 选项：清空重来
        if ($this->option('fresh')) {
            DB::table('sop_documents')->truncate();
            $this->info('已清空 sop_documents 表');
        }

        // 读取 SOP 文件列表
        $sopDir   = public_path();
        $pattern  = $this->option('file')
            ? $sopDir . '/' . $this->option('file') . '*.md'
            : $sopDir . '/SOP-*.md';

        $files = glob($pattern);

        if (empty($files)) {
            $this->error('找不到 SOP 文件，请确认已将 SOP-*.md 放在 public/ 目录');
            return self::FAILURE;
        }

        $this->info('找到 ' . count($files) . ' 份 SOP 文件');
        $totalChunks = 0;

        foreach ($files as $filePath) {
            $fileName = basename($filePath, '.md');
            $fileCode = $this->extractFileCode($fileName); // SOP-001

            $this->line('');
            $this->info("处理：{$fileName}");

            $text   = file_get_contents($filePath);
            $chunks = $this->chunker->chunkByHeading($text);
            $meta   = $this->extractMetadata($text, $fileCode);

            $this->line("  切出 " . count($chunks) . " 个 chunks");

            // 进度条
            $bar = $this->output->createProgressBar(count($chunks));
            $bar->start();

            foreach ($chunks as $chunk) {
                // 把标题和内容合并再 embed，给模型更多上下文
                $textToEmbed = $chunk['title'] . "\n" . $chunk['content'];
                $embedding   = $this->embedder->embed($textToEmbed);

                // 用原生 SQL 插入（因为 vector 类型 Eloquent 不直接支持）
                DB::statement(
                    "INSERT INTO sop_documents
                        (file_code, section_title, content, embedding, metadata, created_at, updated_at)
                     VALUES (?, ?, ?, ?::vector, ?::jsonb, NOW(), NOW())",
                    [
                        $fileCode,
                        $chunk['title'],
                        $chunk['content'],
                        $this->embedder->toVectorString($embedding),
                        json_encode(array_merge($meta, ['chunk_title' => $chunk['title']])),
                    ]
                );

                $bar->advance();

                // 避免打爆 OpenAI rate limit（每次 embed 后等 0.1 秒）
                usleep(100_000);
            }

            $bar->finish();
            $this->line('');
            $totalChunks += count($chunks);
        }

        $this->line('');
        $this->info("完成！共处理 {$totalChunks} 个 chunks");
        $this->table(
            ['统计项目', '数值'],
            [
                ['SOP 文件数', count($files)],
                ['总 chunks 数', $totalChunks],
                ['数据库记录数', DB::table('sop_documents')->count()],
            ]
        );

        return self::SUCCESS;
    }

    // 从文件名提取文件编号：SOP-001-食品安全 → SOP-001
    private function extractFileCode(string $fileName): string
    {
        preg_match('/^(SOP-\d+)/', $fileName, $matches);
        return $matches[1] ?? $fileName;
    }

    // 从文件内容提取 metadata（版本号、生效日期等）
    private function extractMetadata(string $text, string $fileCode): array
    {
        $meta = ['file_code' => $fileCode];

        if (preg_match('/\*\*版本\*\*:\s*(.+)/u', $text, $m)) {
            $meta['version'] = trim($m[1]);
        }
        if (preg_match('/\*\*生效日期\*\*:\s*(.+)/u', $text, $m)) {
            $meta['effective_date'] = trim($m[1]);
        }
        if (preg_match('/\*\*负责部门\*\*:\s*(.+)/u', $text, $m)) {
            $meta['department'] = trim($m[1]);
        }

        return $meta;
    }
}
