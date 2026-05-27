<?php

namespace App\Services;

class ChunkService
{
    // -------------------------------------------------------
    // 策略 1：固定长度切块
    // $size    = 每块最多几个字
    // $overlap = 前后重叠几个字（避免切断句子）
    // -------------------------------------------------------
    public function chunkBySize(string $text, int $size = 500, int $overlap = 50): array
    {
        $chunks = [];
        $length = mb_strlen($text);
        $start  = 0;

        while ($start < $length) {
            $chunk    = mb_substr($text, $start, $size);
            $chunks[] = trim($chunk);
            $start   += $size - $overlap; // 每次往前推 (size - overlap) 个字
        }

        return array_filter($chunks); // 去掉空的
    }

    // -------------------------------------------------------
    // 策略 2：按段落切块
    // 以两个以上换行（空行）为分界
    // -------------------------------------------------------
    public function chunkByParagraph(string $text): array
    {
        // 把 \r\n 统一成 \n，再以两个以上 \n 分割
        $text   = str_replace("\r\n", "\n", $text);
        $chunks = preg_split('/\n{2,}/', $text);

        return array_values(
            array_filter(
                array_map('trim', $chunks),
                fn($c) => mb_strlen($c) > 20 // 过滤太短的（少于20字）
            )
        );
    }

    // -------------------------------------------------------
    // 策略 3：按 Markdown 标题分节（最适合 SOP）
    // 每个 ## 或 ### 标题开始一个新 chunk
    // 回传格式：[['title' => '...', 'content' => '...'], ...]
    // -------------------------------------------------------
    public function chunkByHeading(string $text): array
    {
        $chunks = [];
        $lines  = explode("\n", $text);

        $currentTitle   = '前言';
        $currentContent = [];

        foreach ($lines as $line) {
            // 遇到 ## 或 ### 开头的标题行
            if (preg_match('/^#{1,3}\s+(.+)/', $line, $matches)) {
                // 把上一节存起来
                if (!empty($currentContent)) {
                    $content = trim(implode("\n", $currentContent));
                    if (mb_strlen($content) > 20) {
                        $chunks[] = [
                            'title'   => $currentTitle,
                            'content' => $content,
                        ];
                    }
                }
                // 开始新的一节
                $currentTitle   = trim($matches[1]);
                $currentContent = [];
            } else {
                $currentContent[] = $line;
            }
        }

        // 存最后一节
        if (!empty($currentContent)) {
            $content = trim(implode("\n", $currentContent));
            if (mb_strlen($content) > 20) {
                $chunks[] = [
                    'title'   => $currentTitle,
                    'content' => $content,
                ];
            }
        }

        return $chunks;
    }

    // -------------------------------------------------------
    // 对比工具：显示三种策略的切法统计
    // -------------------------------------------------------
    public function compare(string $text): array
    {
        $bySize      = $this->chunkBySize($text);
        $byParagraph = $this->chunkByParagraph($text);
        $byHeading   = $this->chunkByHeading($text);

        return [
            'fixed_size' => [
                'count'       => count($bySize),
                'avg_length'  => $this->avgLength(array_column($bySize, null) ?: $bySize),
                'sample'      => mb_substr($bySize[0] ?? '', 0, 80) . '...',
            ],
            'paragraph' => [
                'count'       => count($byParagraph),
                'avg_length'  => $this->avgLength($byParagraph),
                'sample'      => mb_substr($byParagraph[0] ?? '', 0, 80) . '...',
            ],
            'heading' => [
                'count'       => count($byHeading),
                'avg_length'  => $this->avgLength(array_column($byHeading, 'content')),
                'sections'    => array_column($byHeading, 'title'),
                'sample'      => mb_substr($byHeading[0]['content'] ?? '', 0, 80) . '...',
            ],
        ];
    }

    private function avgLength(array $chunks): int
    {
        if (empty($chunks)) return 0;
        $total = array_sum(array_map('mb_strlen', $chunks));
        return (int) round($total / count($chunks));
    }
}
