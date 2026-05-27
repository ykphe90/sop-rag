<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class HybridSearchService
{
    // RRF 平滑常数（标准值 60，数值越大越平滑）
    private const RRF_K = 60;

    /**
     * Hybrid Search 主入口
     *
     * 1. 向量搜索  → 取 top_k × 2 候选（多取一些，合并后再截断）
     * 2. 关键词搜索 → 取 top_k × 2 候选
     * 3. RRF 合并   → 按综合分数排序
     * 4. 返回 top_k 结果
     *
     * @param string $queryVector  已转换为字符串的向量 "[0.1, 0.2, ...]"
     * @param string $queryText    原始文字问题（用于关键词搜索）
     * @param int    $topK         最终返回几条
     * @param float  $vectorWeight 向量搜索权重（0~1）
     * @param float  $textWeight   关键词搜索权重（0~1）
     * @return array
     */
    public function search(
        string $queryVector,
        string $queryText,
        int    $topK        = 5,
        float  $vectorWeight = 0.7,
        float  $textWeight   = 0.3
    ): array {
        $candidateK = $topK * 3; // 多取候选，确保合并后有足够结果

        // ── 1. 向量搜索 ──────────────────────────────────────────────
        $vectorResults = $this->vectorSearch($queryVector, $candidateK);

        // ── 2. 关键词搜索 ────────────────────────────────────────────
        $textResults = $this->textSearch($queryText, $candidateK);

        // ── 3. RRF 合并 ──────────────────────────────────────────────
        $merged = $this->rrfMerge($vectorResults, $textResults, $vectorWeight, $textWeight);

        // ── 4. 截断到 top_k ──────────────────────────────────────────
        return array_slice($merged, 0, $topK);
    }

    // ----------------------------------------------------------------
    // 向量搜索（和原来 SearchController 一样）
    // ----------------------------------------------------------------
    private function vectorSearch(string $queryVector, int $limit): array
    {
        $rows = DB::select("
            SELECT
                id,
                file_code,
                section_title,
                content,
                metadata,
                ROUND((1 - (embedding <=> ?::vector))::numeric, 4) AS similarity
            FROM sop_documents
            ORDER BY embedding <=> ?::vector
            LIMIT ?
        ", [$queryVector, $queryVector, $limit]);

        return array_map(fn($r) => (array)$r, $rows);
    }

    // ----------------------------------------------------------------
    // 全文关键词搜索
    //
    // plainto_tsquery('simple', '关店流程') 会把输入拆成词，用 AND 连接
    // ts_rank: PostgreSQL 内建的相关性评分函数
    // ----------------------------------------------------------------
    private function textSearch(string $queryText, int $limit): array
    {
        // 清理查询文字：移除标点，保留词汇
        $cleanText = $this->prepareTextQuery($queryText);

        if (empty($cleanText)) {
            return [];
        }

        try {
            $rows = DB::select("
                SELECT
                    id,
                    file_code,
                    section_title,
                    content,
                    metadata,
                    ROUND(ts_rank(content_tsv, plainto_tsquery('simple', ?))::numeric, 4) AS text_score
                FROM sop_documents
                WHERE content_tsv @@ plainto_tsquery('simple', ?)
                ORDER BY text_score DESC
                LIMIT ?
            ", [$cleanText, $cleanText, $limit]);

            return array_map(fn($r) => (array)$r, $rows);
        } catch (\Exception $e) {
            // 如果全文搜索失败（例如 tsv 列不存在），回退到空结果
            \Log::warning('HybridSearch: text search failed: ' . $e->getMessage());
            return [];
        }
    }

    // ----------------------------------------------------------------
    // RRF（Reciprocal Rank Fusion）合并算法
    //
    // 原理：
    //   每个文档的最终分数 = Σ [weight × 1/(k + rank)]
    //   rank 从 1 开始（排名第一的文档得分最高）
    //
    // 例子：
    //   文档 A：向量排名 #1，关键词排名 #2
    //   分数 = 0.7×(1/61) + 0.3×(1/62) = 0.01148 + 0.00484 = 0.01632
    //
    //   文档 B：向量排名 #3，关键词排名 #1
    //   分数 = 0.7×(1/63) + 0.3×(1/61) = 0.01111 + 0.00492 = 0.01603
    // ----------------------------------------------------------------
    private function rrfMerge(
        array $vectorResults,
        array $textResults,
        float $vectorWeight,
        float $textWeight
    ): array {
        $scores = []; // [id => ['score' => float, 'data' => array]]

        // 处理向量搜索排名
        foreach ($vectorResults as $rank => $row) {
            $id = $row['id'];
            $rrfScore = $vectorWeight / (self::RRF_K + $rank + 1);

            $scores[$id] = [
                'score'          => $rrfScore,
                'vector_rank'    => $rank + 1,
                'vector_sim'     => $row['similarity'] ?? null,
                'text_rank'      => null,
                'text_score'     => null,
                'data'           => $row,
                'search_type'    => 'vector',
            ];
        }

        // 处理关键词搜索排名（与向量结果合并）
        foreach ($textResults as $rank => $row) {
            $id = $row['id'];
            $rrfScore = $textWeight / (self::RRF_K + $rank + 1);

            if (isset($scores[$id])) {
                // 两种搜索都找到了这个文档 → 分数相加
                $scores[$id]['score']      += $rrfScore;
                $scores[$id]['text_rank']   = $rank + 1;
                $scores[$id]['text_score']  = $row['text_score'] ?? null;
                $scores[$id]['search_type'] = 'hybrid'; // 两种都命中
            } else {
                // 只有关键词搜索找到
                $scores[$id] = [
                    'score'          => $rrfScore,
                    'vector_rank'    => null,
                    'vector_sim'     => null,
                    'text_rank'      => $rank + 1,
                    'text_score'     => $row['text_score'] ?? null,
                    'data'           => $row,
                    'search_type'    => 'text',
                ];
            }
        }

        // 按综合分数排序
        uasort($scores, fn($a, $b) => $b['score'] <=> $a['score']);

        // 组装最终格式（和原来 vector search 返回格式一致）
        return array_values(array_map(function ($item) {
            $row = $item['data'];
            return [
                'id'           => $row['id'],
                'file_code'    => $row['file_code'],
                'section_title'=> $row['section_title'],
                'content'      => $row['content'],
                'metadata'     => $row['metadata'],
                'similarity'   => $item['vector_sim'] ?? 0,   // 向量相似度
                'rrf_score'    => round($item['score'], 6),    // RRF 综合分数
                'search_type'  => $item['search_type'],        // 来源标注
                'vector_rank'  => $item['vector_rank'],
                'text_rank'    => $item['text_rank'],
            ];
        }, $scores));
    }

    // ----------------------------------------------------------------
    // 清理查询文字，让 plainto_tsquery 能正常工作
    // ----------------------------------------------------------------
    private function prepareTextQuery(string $text): string
    {
        // 移除标点符号，保留中文、英文、数字
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        // 压缩多余空格
        $text = preg_replace('/\s+/', ' ', trim($text));
        return $text;
    }
}
