<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\CatalogCache;
use App\Domain\Catalog\Product;
use App\Domain\Delivery\Delivery;
use App\Domain\Order\Order;
use App\Domain\Order\OrderStatus;
use App\Domain\Reconcile\ReconcileReport;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverOrderJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Служебные ручки. Авторизации нет сознательно — её нет во всём задании
 * (SPEC §0.3); в бою этот префикс закрывается на уровне ingress.
 */
class AdminController extends Controller
{
    public function __construct(private readonly CatalogCache $catalogCache) {}

    public function reconcile(ReconcileReport $report): JsonResponse
    {
        $result = $report->build();

        // Отчёт, который никто не читает, бесполезен: пишем находки в лог,
        // чтобы они попадали в общий поток, а не только в ответ ручки.
        if (! $result['healthy']) {
            Log::warning('reconcile.finding', [
                'event' => 'reconcile.finding',
                'outcome' => 'unhealthy',
                'findings' => array_map(
                    'count',
                    array_diff_key($result, ['ledger' => null, 'healthy' => null]),
                ),
            ]);
        }

        return response()->json($result);
    }

    /**
     * Ручное доведение. Отличается от фонового только тем, что не ждёт
     * бэкоффа и обнуляет счётчик циклов: человек вмешался, значит причину
     * зависания он посмотрел и даёт заказу свежий бюджет попыток.
     */
    public function retryDelivery(string $id): JsonResponse
    {
        $order = Order::find($id);

        if ($order === null) {
            return response()->json(['error' => 'order_not_found', 'id' => $id], 404);
        }

        if (Delivery::where('order_id', $order->id)->exists()) {
            return response()->json([
                'error' => 'already_delivered',
                'id' => $order->id,
                'status' => $order->status->value,
            ], 409);
        }

        if (! $order->status->isPaidSide()) {
            return response()->json([
                'error' => 'not_payable_for_delivery',
                'id' => $order->id,
                'status' => $order->status->value,
            ], 409);
        }

        DB::table('orders')->where('id', $order->id)->update([
            'attempts' => 0,
            'next_attempt_at' => null,
            'updated_at' => now(),
        ]);

        DeliverOrderJob::dispatch($order->id)->afterCommit();

        Log::info('delivery.retry_requested', [
            'event' => 'delivery.retry_requested',
            'order_id' => $order->id,
            'outcome' => 'dispatched',
            'status' => $order->status->value,
        ]);

        return response()->json([
            'id' => $order->id,
            'status' => $order->status->value,
            'result' => 'delivery_dispatched',
        ]);
    }

    /**
     * Пополнение витринного остатка.
     *
     * Витрина — денормализованный счётчик, а не источник правды: настоящие
     * ключи лежат у поставщика. Поэтому ручка делает две вещи — поднимает
     * счётчик и будит заказы, которые ушли в out_of_stock по этому sku,
     * сбрасывая им бэкофф. Дальше их забирает обычное фоновое доведение.
     */
    public function refillStock(Request $request, string $sku): JsonResponse
    {
        $product = Product::find($sku);

        if ($product === null) {
            return response()->json(['error' => 'sku_not_found', 'sku' => $sku], 404);
        }

        $quantity = (int) $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ])['quantity'];

        $woken = DB::transaction(function () use ($sku, $quantity): int {
            DB::table('product_stock')->where('sku', $sku)->update([
                'available' => DB::raw("available + {$quantity}"),
                'updated_at' => now(),
            ]);

            return DB::table('orders')
                ->where('sku', $sku)
                ->where('status', OrderStatus::OutOfStock->value)
                ->update(['next_attempt_at' => now(), 'attempts' => 0, 'updated_at' => now()]);
        });

        $this->catalogCache->invalidate();

        $available = (int) DB::table('product_stock')->where('sku', $sku)->value('available');

        Log::info('stock.refilled', [
            'event' => 'stock.refilled',
            'sku' => $sku,
            'outcome' => 'refilled',
            'quantity' => $quantity,
            'available' => $available,
            'woken_orders' => $woken,
        ]);

        return response()->json([
            'sku' => $sku,
            'available' => $available,
            'woken_orders' => $woken,
        ]);
    }
}
