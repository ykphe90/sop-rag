<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class EmbeddingController extends Controller
{
    // -------------------------------------------------------
    // POST /api/embed
    // 输入：{ "text": "鸡肉须冷藏在 4°C 以下" }
    // 输出：{ "dimensions": 1536, "preview": [...], "embedding": [...] }
    // -------------------------------------------------------
    public function embed(Request $request)
    {
        $request->validate([
            'text' => 'required|string|max:8000',
        ]);

        $response = Http::withToken(config('services.openai.key'))
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => 'text-embedding-3-small',  // 1536 维，便宜又够用
                'input' => $request->text,
            ]);

        if ($response->failed()) {
            return response()->json([
                'error' => 'OpenAI API error',
                'detail' => $response->json('error.message'),
            ], 500);
        }

        $embedding = $response->json('data.0.embedding');

        return response()->json([
            'text'       => $request->text,
            'model'      => 'text-embedding-3-small',
            'dimensions' => count($embedding),          // 应该是 1536
            'preview'    => array_slice($embedding, 0, 8), // 只看前 8 个感受一下
            'embedding'  => $embedding,                 // 完整 1536 维向量
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // -------------------------------------------------------
    // POST /api/similarity
    // 输入：{ "text1": "...", "text2": "..." }
    // 输出：{ "cosine_similarity": 0.92, "interpretation": "非常相似" }
    // -------------------------------------------------------
    public function similarity(Request $request)
    {
        $request->validate([
            'text1' => 'required|string|max:8000',
            'text2' => 'required|string|max:8000',
        ]);

        // 同时取两个 embedding（两次 API call）
        $vec1 = $this->getEmbedding($request->text1);
        $vec2 = $this->getEmbedding($request->text2);

        $score = $this->cosineSimilarity($vec1, $vec2);

        return response()->json([
            'text1'              => $request->text1,
            'text2'              => $request->text2,
            'cosine_similarity'  => round($score, 4),
            'interpretation'     => $this->interpret($score),
        ], 200, [], JSON_UNESCAPED_UNICODE); 
    }

    // -------------------------------------------------------
    // 私有方法
    // -------------------------------------------------------

    private function getEmbedding(string $text): array
    {
        $response = Http::withToken(config('services.openai.key'))
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => 'text-embedding-3-small',
                'input' => $text,
            ]);

        return $response->json('data.0.embedding');
    }

    /**
     * Cosine Similarity 公式：
     * similarity = (A · B) / (|A| × |B|)
     *
     * A · B  = 点积（dot product）
     * |A|    = 向量 A 的长度（magnitude）
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        // 点积：把对应位置的数字相乘再全部加起来
        $dotProduct = array_sum(
            array_map(fn($x, $y) => $x * $y, $a, $b)
        );

        // 各自的 magnitude（长度）
        $magA = sqrt(array_sum(array_map(fn($x) => $x * $x, $a)));
        $magB = sqrt(array_sum(array_map(fn($x) => $x * $x, $b)));

        if ($magA == 0 || $magB == 0) {
            return 0.0;
        }

        return $dotProduct / ($magA * $magB);
    }

    private function interpret(float $score): string
    {
        return match(true) {
            $score >= 0.90 => '非常相似（几乎同义）',
            $score >= 0.75 => '高度相似（同一主题）',
            $score >= 0.50 => '有些相关',
            $score >= 0.25 => '略有关联',
            default        => '不相关',
        };
    }
}
