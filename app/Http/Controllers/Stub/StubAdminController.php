<?php

namespace App\Http\Controllers\Stub;

use App\Domain\Stub\StubConfig;
use App\Domain\Stub\StubState;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StubAdminController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier' => ['required', Rule::in(StubConfig::SUPPLIERS)],
            'fail_rate' => ['sometimes', 'numeric', 'between:0,1'],
            'timeout_rate' => ['sometimes', 'numeric', 'between:0,1'],
            'latency_ms' => ['sometimes', 'integer', 'min:0', 'max:60000'],
            'timeout_sleep_ms' => ['sometimes', 'integer', 'min:0', 'max:120000'],
            'force' => ['sometimes', Rule::in(StubConfig::FORCE_MODES)],
        ]);

        $supplier = $data['supplier'];
        unset($data['supplier']);

        return response()->json([
            'supplier' => $supplier,
            'config' => StubConfig::set($supplier, $data),
        ]);
    }

    public function state(StubState $state): JsonResponse
    {
        return response()->json($state->snapshot());
    }

    public function reset(StubState $state): JsonResponse
    {
        $state->reset();

        return response()->json(['status' => 'ok'] + $state->snapshot());
    }
}
