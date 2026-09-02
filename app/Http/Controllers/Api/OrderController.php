<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\Product;
use App\Domain\Order\Order;
use App\Domain\Order\OrderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function store(CreateOrderRequest $request, OrderService $orders): JsonResponse
    {
        $sku = (string) $request->validated('sku');
        $product = Product::find($sku);

        if ($product === null) {
            return response()->json(['error' => 'sku_not_found', 'sku' => $sku], 404);
        }

        if (! $product->is_active) {
            return response()->json(['error' => 'sku_inactive', 'sku' => $sku], 409);
        }

        [$order, $created] = $orders->create($product, $request->idempotencyKey());

        // Повтор с тем же Idempotency-Key — 200 и тот же заказ, не второй.
        return OrderResource::make($order)
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function show(string $id): JsonResponse
    {
        $order = Order::with('delivery')->find($id);

        if ($order === null) {
            return response()->json(['error' => 'order_not_found', 'id' => $id], 404);
        }

        return OrderResource::make($order)->response();
    }
}
