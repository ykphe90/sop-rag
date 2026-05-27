<?php

namespace App\Http\Controllers;

use App\Services\EmbeddingService;
use App\Services\HybridSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    public function __construct(
        private EmbeddingService    $embedder,
        private HybridSearchService $hybridSearch,
    ) {}

    // -------------------------------------------------------
    // POST /api/search  （原有向量搜索，保持不变）
    // -------------------------------------------------------
    public function search(Request $request)
    {
        $request->validate([
            'query' => 'required|string|max:1000',
            'top_k' => 'integer|min:1|max:20',
        ]);

        $query  = $request->input('query');
        $topK   = $request->top_k ?? 5;

        $queryEmbedding = $this->embedder->embed($query);
        $queryVector    = $this->embedder->toVectorString($queryEmbedding);

        $results = DB::select("
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
        ", [$queryVector, $queryVector, $topK]);

        $formatted = array_map(function ($row) {
            $meta = json_decode($row->metadata, true) ?? [];
            return [
                'file_code'     => $row->file_code,
                'section_title' => $row->section_title,
                'similarity'    => (float) $row->similarity,
                'content'       => mb_substr($row->content, 0, 200) . '...',
                'version'       => $meta['version'] ?? null,
            ];
        }, $results);

        return response()->json([
            'query'   => $query,
            'top_k'   => $topK,
            'results' => $formatted,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // -------------------------------------------------------
    // POST /api/search-hybrid  （新：向量 + 关键词混合搜索）
    // 输入：{ "query": "厨房关店流程", "top_k": 5, "vector_weight": 0.7 }
    // 输出：混合搜索结果，附来源标注（vector/text/hybrid）
    // -------------------------------------------------------
    public function searchHybrid(Request $request)
    {
        $request->validate([
            'query'         => 'required|string|max:1000',
            'top_k'         => 'integer|min:1|max:20',
            'vector_weight' => 'numeric|min:0|max:1',
        ]);

        $query        = $request->input('query');
        $topK         = $request->input('top_k', 5);
        $vectorWeight = $request->input('vector_weight', 0.7);
        $textWeight   = 1 - $vectorWeight;

        // 向量化查询
        $queryEmbedding = $this->embedder->embed($query);
        $queryVector    = $this->embedder->toVectorString($queryEmbedding);

        // 混合搜索
        $results = $this->hybridSearch->search(
            queryVector:  $queryVector,
            queryText:    $query,
            topK:         $topK,
            vectorWeight: $vectorWeight,
            textWeight:   $textWeight,
        );

        // 整理输出格式
        $formatted = array_map(function ($row) {
            $meta = is_string($row['metadata'])
                ? (json_decode($row['metadata'], true) ?? [])
                : ($row['metadata'] ?? []);
            return [
                'file_code'     => $row['file_code'],
                'section_title' => $row['section_title'],
                'similarity'    => (float) $row['similarity'],
                'rrf_score'     => (float) $row['rrf_score'],
                'search_type'   => $row['search_type'],
                'vector_rank'   => $row['vector_rank'],
                'text_rank'     => $row['text_rank'],
                'content'       => mb_substr($row['content'], 0, 200) . '...',
                'version'       => $meta['version'] ?? null,
            ];
        }, $results);

        return response()->json([
            'query'         => $query,
            'top_k'         => $topK,
            'vector_weight' => $vectorWeight,
            'text_weight'   => $textWeight,
            'results'       => $formatted,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
