<?php
use App\Http\Controllers\EmbeddingController;
use App\Services\ChunkService;
use App\Http\Controllers\SearchController;
use App\Services\PromptBuilder;
use App\Services\EmbeddingService;
use App\Http\Controllers\AskController;
use Illuminate\Support\Facades\Route;

Route::post('/embed',      [EmbeddingController::class, 'embed']);
Route::post('/similarity', [EmbeddingController::class, 'similarity']);
Route::get('/ping',        fn() => response()->json(['status' => 'ok']));

Route::get('/chunk-test', function () {
    $sopPath = base_path('public/SOP-001-食品安全与卫生管理.md');
    $text    = file_get_contents($sopPath);
    $service = new ChunkService();
    return response()->json($service->compare($text), 200, [], JSON_UNESCAPED_UNICODE);
});

Route::post('/search',        [SearchController::class, 'search']);
Route::post('/search-hybrid', [SearchController::class, 'searchHybrid']);  // ← 新增

Route::get('/prompt-preview', function () {
    $chunks = [
        ['file_code' => 'SOP-001', 'section_title' => '4.2 储存原则（FIFO）', 'content' => '冷藏库每 4 小时记录温度（目标 0-4°C）\n冷冻库每 4 小时记录温度（目标 -18°C 以下）', 'similarity' => 0.82],
        ['file_code' => 'SOP-001', 'section_title' => '5.1 最低烹饪中心温度', 'content' => '家禽类（鸡、鸭）：74°C，至少 15 秒', 'similarity' => 0.76],
    ];

    $builder  = new PromptBuilder();
    $messages = $builder->buildMessages('鸡肉应该存放在什么温度？', $chunks);

    return response()->json([
        'messages'        => $messages,
        'estimated_tokens' => $builder->estimateTokens($messages),
    ], 200, [], JSON_UNESCAPED_UNICODE);
});

Route::post('/ask', [AskController::class, 'ask']);
