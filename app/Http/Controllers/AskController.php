<?php

namespace App\Http\Controllers;

use App\Services\EmbeddingService;
use App\Services\HybridSearchService;
use App\Services\RerankService;
use App\Services\LlmService;
use App\Services\PromptBuilder;
use Illuminate\Http\Request;

class AskController extends Controller
{
    public function __construct(
        private EmbeddingService    $embedder,
        private HybridSearchService $hybridSearch,
        private RerankService       $reranker,
        private LlmService          $llm,
        private PromptBuilder       $promptBuilder,
    ) {}

    // -------------------------------------------------------
    // POST /api/ask
    // 输入：{
    //   "question": "那病假呢？",
    //   "history": [
    //     {"role": "user", "content": "员工年假有多少天？"},
    //     {"role": "assistant", "content": "1年8天..."}
    //   ],
    //   "top_k": 5,
    //   "lang": "zh"
    // }
    // -------------------------------------------------------
    public function ask(Request $request)
    {
        $request->validate([
            'question'      => 'required|string|max:1000',
            'top_k'         => 'integer|min:1|max:10',
            'lang'          => 'in:zh,en,ms',
            'history'       => 'array|max:20',
            'history.*.role'    => 'in:user,assistant',
            'history.*.content' => 'string|max:2000',
        ]);

        $question = $request->input('question');
        $topK     = $request->input('top_k', 5);
        $lang     = $request->input('lang', 'zh');
        $history  = $request->input('history', []);

        // ── Step 1: Embed ─────────────────────────────────
        $queryEmbedding = $this->embedder->embed($question);
        $queryVector    = $this->embedder->toVectorString($queryEmbedding);

        // ── Step 2: Hybrid Search ─────────────────────────
        $candidates = $this->hybridSearch->search(
            queryVector:  $queryVector,
            queryText:    $question,
            topK:         $topK * 2,
            vectorWeight: 0.7,
            textWeight:   0.3,
        );

        $chunks = array_map(fn($r) => [
            'file_code'     => $r['file_code'],
            'section_title' => $r['section_title'],
            'content'       => $r['content'],
            'similarity'    => (float) $r['similarity'],
            'rrf_score'     => (float) $r['rrf_score'],
            'search_type'   => $r['search_type'],
            'rerank_score'  => null,
            'version'       => is_string($r['metadata'])
                ? (json_decode($r['metadata'], true)['version'] ?? null)
                : ($r['metadata']['version'] ?? null),
        ], $candidates);

        // ── Step 3: Rerank ────────────────────────────────
        $reranked = $this->reranker->rerank($question, $chunks, topN: $topK);

        // ── Step 4: Augment + Generate（附历史）────────────
        $safeChunks = $this->promptBuilder->trimToTokenLimit($reranked, maxTokens: 2500); // 稍微缩小，给历史留空间
        $messages   = $this->promptBuilder->buildMessages($question, $safeChunks, $lang, $history);
        $llmResult  = $this->llm->chat($messages);

        $sources = array_map(fn($c) => [
            'file_code'    => $c['file_code'],
            'section'      => $c['section_title'],
            'similarity'   => $c['similarity'],
            'rrf_score'    => $c['rrf_score'],
            'rerank_score' => $c['rerank_score'],
            'search_type'  => $c['search_type'],
            'version'      => $c['version'],
        ], $safeChunks);

        return response()->json([
            'question'    => $question,
            'lang'        => $lang,
            'answer'      => $llmResult['content'],
            'sources'     => $sources,
            'model'       => $llmResult['model'],
            'tokens_used' => $llmResult['tokens_used'],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
