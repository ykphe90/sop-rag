<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class EmbeddingService
{
    private string $model = 'text-embedding-3-small';

    // -------------------------------------------------------
    // 单条文字 → 向量
    // -------------------------------------------------------
    public function embed(string $text): array
    {
        $response = Http::withToken(config('services.openai.key'))
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => $this->model,
                'input' => $text,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                'OpenAI embedding failed: ' . $response->json('error.message')
            );
        }

        return $response->json('data.0.embedding');
    }

    // -------------------------------------------------------
    // 多条文字批次处理（减少 API call 次数）
    // OpenAI 支持一次传多条 input
    // -------------------------------------------------------
    public function embedBatch(array $texts): array
    {
        $response = Http::withToken(config('services.openai.key'))
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => $this->model,
                'input' => $texts,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                'OpenAI embedding batch failed: ' . $response->json('error.message')
            );
        }

        // 回传的顺序和 input 顺序一致
        return array_column($response->json('data'), 'embedding');
    }

    // 向量转成 PostgreSQL vector 格式的字符串
    public function toVectorString(array $embedding): string
    {
        return '[' . implode(',', $embedding) . ']';
    }
}
