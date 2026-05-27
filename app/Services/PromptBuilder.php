<?php

namespace App\Services;

class PromptBuilder
{
    public function buildSystemPrompt(string $lang = 'zh'): string
    {
        if ($lang === 'en') {
            return <<<PROMPT
You are a professional F&B SOP assistant. Your job is to answer staff questions based on the company's Standard Operating Procedures.

Your rules:
1. Answer based on the SOP content provided below. You may reasonably infer and synthesise relevant information.
2. Always cite your source using only the SOP code (e.g. "SOP-005, Section 3.2" or "per SOP-004"). Do NOT include Chinese section titles in your answer.
3. If the SOP content is insufficient to answer the question, clearly state: "This information is not found in the SOP."
4. Answer entirely in clear, concise English. Do not mix in any Chinese characters.
5. If there are specific figures (temperatures, timeframes, percentages), quote them precisely.
6. You have access to previous conversation history. Use it to understand follow-up questions and context.
PROMPT;
        }

        return <<<PROMPT
你是一位专业的餐饮业 SOP 助理，负责根据公司标准作业程序回答员工的问题。

你的规则：
1. 根据下方提供的 SOP 内容作答，可以合理推断和综合相关信息
2. 回答时注明来源（哪份 SOP 的哪个章节）
3. 如果 SOP 内容不足以回答问题，明确说明「SOP 中未找到相关规定」
4. 用简洁清晰的中文回答
5. 如有具体数据（温度、时间、百分比），务必精确引用
6. 你可以参考之前的对话历史，理解追问和上下文
PROMPT;
    }

    public function buildContext(array $chunks): string
    {
        if (empty($chunks)) {
            return '=== No relevant SOP content found ===';
        }

        $lines = ['=== Relevant SOP Content ===', ''];

        foreach ($chunks as $chunk) {
            $lines[] = "[{$chunk['file_code']} · {$chunk['section_title']}]";
            $lines[] = $chunk['content'];
            $lines[] = '';
        }

        $lines[] = '=== End of SOP Content ===';

        return implode("\n", $lines);
    }

    // -------------------------------------------------------
    // 组装 messages，支持对话历史
    //
    // 结构：
    //   [system]
    //   [user] 历史问题1    ← 最近 N 轮
    //   [assistant] 历史答案1
    //   ...
    //   [user] SOP context + 当前问题   ← 永远是最后一条
    //
    // $history 格式：[['role'=>'user','content'=>'...'], ['role'=>'assistant','content'=>'...']]
    // -------------------------------------------------------
    public function buildMessages(string $userQuestion, array $chunks, string $lang = 'zh', array $history = []): array
    {
        $context = $this->buildContext($chunks);

        $messages = [
            [
                'role'    => 'system',
                'content' => $this->buildSystemPrompt($lang),
            ],
        ];

        // 插入历史对话（最多保留 6 条，即 3 轮问答）
        $recentHistory = array_slice($history, -6);
        foreach ($recentHistory as $turn) {
            $messages[] = [
                'role'    => $turn['role'],
                'content' => $turn['content'],
            ];
        }

        // 当前问题（附 SOP context）
        $messages[] = [
            'role'    => 'user',
            'content' => $context . "\n\nQuestion: " . $userQuestion,
        ];

        return $messages;
    }

    public function estimateTokens(array $messages): int
    {
        $text = collect($messages)->pluck('content')->implode(' ');
        return (int) (mb_strlen($text) * 1.5);
    }

    public function trimToTokenLimit(array $chunks, int $maxTokens = 3000): array
    {
        $trimmed = [];
        $used    = 0;

        foreach ($chunks as $chunk) {
            $chunkTokens = (int) (mb_strlen($chunk['content']) * 1.5);
            if ($used + $chunkTokens > $maxTokens) break;
            $trimmed[] = $chunk;
            $used += $chunkTokens;
        }

        return $trimmed;
    }
}
