<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class LlmService
{
    // -------------------------------------------------------
    // 调用 OpenAI Chat Completions API
    // $messages 格式：[['role'=>'system','content'=>'...'], ['role'=>'user','content'=>'...']]
    // -------------------------------------------------------
    public function chat(array $messages, string $model = 'gpt-4o-mini'): array
    {
        $response = Http::withToken(config('services.openai.key'))
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'       => $model,
                'messages'    => $messages,
                'temperature' => 0.1,    // 低温度 = 更保守、更忠实于 context
                'max_tokens'  => 1000,   // 答案最多 1000 tokens
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                'OpenAI chat failed: ' . $response->json('error.message')
            );
        }

        return [
            'content'     => $response->json('choices.0.message.content'),
            'model'       => $response->json('model'),
            'tokens_used' => $response->json('usage'),
        ];
    }
}
