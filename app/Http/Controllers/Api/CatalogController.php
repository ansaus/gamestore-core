<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\CatalogCache;
use App\Domain\Catalog\CatalogQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class CatalogController extends Controller
{
    public function index(CatalogRequest $request, CatalogQuery $catalog, CatalogCache $cache): JsonResponse
    {
        $params = [
            'type' => $request->validated('type'),
            'after' => $request->validated('after'),
            'limit' => (int) ($request->validated('limit') ?? 50),
        ];

        $page = Cache::remember(
            $cache->key($params),
            $cache->ttl(),
            fn () => $catalog->page($params['type'], $params['after'], $params['limit']),
        );

        return response()->json($page);
    }
}
