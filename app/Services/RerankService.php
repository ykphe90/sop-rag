<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RerankService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.cohere.key');
        $this->model  = 'rerank-v3.5';   // Cohere 最新 rerank 模型
    }

    // -------------------------------------------------------
    // Rerank 主入口
    //
    // 流程：
    //   1. 把所有 chunks 的 content 送给 Cohere
    //   2. Cohere 用 cross-encoder 对「问题 + 每个 chunk」打分
    //   3. 按新分数排序，取 top_n
    //
    // @param string $query   用户的原始问题
    // @param array  $chunks  来自 HybridSearch 的候选 chunks
    // @param int    $topN    rerank 后取几条
    // @return array          重新排序后的 chunks（加了 rerank_score 字段）
    // -------------------------------------------------------
    public function rerank(string $query, array $chunks, int $topN = 5): array
    {
        if (empty($chunks)) {
            return [];
        }

        // Cohere 只需要纯文字，我们把 section_title + content 合并送过去
        // 这样 reranker 也能理解章节标题的语义
        $documents = array_map(
            fn($c) => $c['section_title'] . "\n" . $c['content'],
            $chunks
        );

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(15)
                ->post('https://api.cohere.com/v2/rerank', [
                    'model'            => $this->model,
                    'query'            => $query,
                    'documents'        => $documents,
                    'top_n'            => $topN,
                    'return_documents' => false,   // 我们不需要 Cohere 返回文字，自己有
                ]);

            if (!$response->successful()) {
                Log::warning('Cohere rerank failed: ' . $response->body());
                return array_slice($chunks, 0, $topN);   // fallback：原顺序截断
            }

            $results = $response->json('results');

            // results 格式：[{ "index": 2, "relevance_score": 0.98 }, ...]
            // index 对应 $documents 数组的位置（也就是 $chunks 的位置）
            $reranked = array_map(function ($result) use ($chunks) {
                $chunk = $chunks[$result['index']];
                $chunk['rerank_score'] = round($result['relevance_score'], 4);
                return $chunk;
            }, $results);

            return $reranked;

        } catch (\Exception $e) {
            Log::warning('Cohere rerank exception: ' . $e->getMessage());
            return array_slice($chunks, 0, $topN);   // fallback：原顺序截断
        }
    }
}
