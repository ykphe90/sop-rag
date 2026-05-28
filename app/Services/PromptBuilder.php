<?php

namespace App\Services;

class PromptBuilder
{
    public function buildSystemPrompt(string $lang = 'zh'): string
    {
        return match($lang) {
            'en' => <<<PROMPT
You are a professional F&B SOP assistant. Your job is to answer staff questions based on the company's Standard Operating Procedures.

Your rules:
1. Answer based on the SOP content provided below. You may reasonably infer and synthesise relevant information.
2. Always cite your source using only the SOP code (e.g. "SOP-005, Section 3.2"). Do NOT include Chinese section titles in your answer.
3. If the SOP content is insufficient to answer the question, clearly state: "This information is not found in the SOP."
4. Answer entirely in clear, concise English. Do not mix in any other language.
5. If there are specific figures (temperatures, timeframes, percentages), quote them precisely.
6. You have access to previous conversation history. Use it to understand follow-up questions and context.
PROMPT,

            'ms' => <<<PROMPT
Anda adalah pembantu SOP F&B yang profesional. Tugas anda adalah menjawab soalan kakitangan berdasarkan Prosedur Operasi Standard syarikat.

Peraturan anda:
1. Jawab berdasarkan kandungan SOP yang diberikan di bawah. Anda boleh membuat kesimpulan dan sintesis maklumat yang berkaitan.
2. Sentiasa nyatakan sumber menggunakan kod SOP sahaja (contoh: "SOP-005, Seksyen 3.2"). JANGAN masukkan tajuk seksyen dalam bahasa Cina.
3. Jika kandungan SOP tidak mencukupi untuk menjawab soalan, nyatakan dengan jelas: "Maklumat ini tidak terdapat dalam SOP."
4. Jawab sepenuhnya dalam Bahasa Malaysia yang jelas dan ringkas. Jangan campur bahasa lain.
5. Jika terdapat angka khusus (suhu, masa, peratusan), petik dengan tepat.
6. Anda mempunyai akses kepada sejarah perbualan sebelumnya. Gunakan untuk memahami soalan susulan dan konteks.
PROMPT,

            default => <<<PROMPT
你是一位专业的餐饮业 SOP 助理，负责根据公司标准作业程序回答员工的问题。

你的规则：
1. 根据下方提供的 SOP 内容作答，可以合理推断和综合相关信息
2. 回答时注明来源（哪份 SOP 的哪个章节）
3. 如果 SOP 内容不足以回答问题，明确说明「SOP 中未找到相关规定」
4. 用简洁清晰的中文回答
5. 如有具体数据（温度、时间、百分比），务必精确引用
6. 你可以参考之前的对话历史，理解追问和上下文
PROMPT,
        };
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

    public function buildMessages(string $userQuestion, array $chunks, string $lang = 'zh', array $history = []): array
    {
        $context = $this->buildContext($chunks);

        $messages = [[
            'role'    => 'system',
            'content' => $this->buildSystemPrompt($lang),
        ]];

        foreach (array_slice($history, -6) as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }

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

    public function trimToTokenLimit(array $chunks, int $maxTokens = 2500): array
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
