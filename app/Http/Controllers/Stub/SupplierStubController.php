<?php

namespace App\Http\Controllers\Stub;

use App\Domain\Stub\StubConfig;
use App\Domain\Stub\StubIssuer;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierStubController extends Controller
{
    public function issue(Request $request, string $supplier, StubIssuer $issuer): JsonResponse
    {
        $supplier = strtoupper($supplier);

        if (! in_array($supplier, StubConfig::SUPPLIERS, true)) {
            return response()->json(['status' => 'error', 'reason' => 'unknown_supplier'], 404);
        }

        $data = $request->validate([
            'request_id' => ['required', 'string', 'max:200'],
            'order_id' => ['required', 'string', 'max:200'],
            'sku' => ['required', 'string', 'max:200'],
        ]);

        $result = $issuer->issue($supplier, $data['request_id'], $data['order_id'], $data['sku']);

        if ($result->status !== 200) {
            return response()->json(['status' => 'error', 'reason' => $result->reason], $result->status);
        }

        return response()->json([
            'status' => 'ok',
            'request_id' => $data['request_id'],
            'code' => $result->code,
            // Не часть контракта поставщика, но однозначно показывает,
            // что повтор обслужен ранее выданным кодом.
            'replayed' => $result->replayed,
        ]);
    }
}
