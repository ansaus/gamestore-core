<?php

namespace App\Domain\Delivery;

use App\Domain\Ledger\Ledger;
use App\Domain\Order\Order;
use App\Domain\Order\OrderStatus;
use App\Domain\Order\OrderTransitions;
use App\Support\Supplier\SupplierClient;
use App\Support\Supplier\SupplierOutcome;
use App\Support\Supplier\SupplierOutcomeKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Выдача заказа.
 *
 * Структура метода подчинена инварианту I7: HTTP к поставщику идёт МЕЖДУ
 * двумя транзакциями, а не внутри одной. Держать открытую транзакцию на
 * время сетевого вызова — это блокировка строки заказа на секунды и
 * гарантированный затык под нагрузкой.
 *
 * Этап 1: один поставщик, одна попытка. Ретраи тем же request_id,
 * фолбэк A→B и разбор состояния unknown — этап 3.
 */
class DeliveryService
{
    public function __construct(
        private readonly SupplierClient $client,
        private readonly OrderTransitions $transitions,
        private readonly Ledger $ledger,
    ) {}

    public function deliver(string $orderId): void
    {
        $order = $this->beginDelivering($orderId);

        if ($order === null) {
            return;
        }

        $supplier = (string) config('gamestore.supplier.order')[0];
        $requestId = SupplierRequest::idFor($order->id, $supplier);

        $this->registerAttempt($order, $supplier, $requestId);

        Log::info('delivery.started', [
            'event' => 'delivery.started',
            'order_id' => $order->id,
            'request_id' => $requestId,
            'supplier' => $supplier,
        ]);

        // --- вне транзакции ---
        $outcome = $this->client->issue($supplier, $requestId, $order->id, $order->sku);
        // ----------------------

        match ($outcome->kind) {
            SupplierOutcomeKind::Succeeded => $this->finalize($order, $supplier, $requestId, (string) $outcome->code),
            SupplierOutcomeKind::Rejected => $this->reject($order, $requestId, $outcome),
            SupplierOutcomeKind::Unknown => $this->giveUpForNow($order, $requestId, $outcome),
        };
    }

    /**
     * Переводит заказ в delivering и возвращает его, либо null — если выдавать нечего.
     */
    private function beginDelivering(string $orderId): ?Order
    {
        return DB::transaction(function () use ($orderId): ?Order {
            $order = Order::where('id', $orderId)->lockForUpdate()->first();

            if ($order === null) {
                return null;
            }

            // Повторный запуск для уже выданного заказа — штатный no-op,
            // на этом стоит безопасность фонового доведения.
            $startable = [OrderStatus::Paid, OrderStatus::OutOfStock, OrderStatus::DeliveryFailed];

            if (! in_array($order->status, $startable, true)) {
                return null;
            }

            $this->transitions->apply($orderId, [$order->status], OrderStatus::Delivering, [
                'attempts' => DB::raw('attempts + 1'),
            ]);

            return $order->refresh();
        });
    }

    private function registerAttempt(Order $order, string $supplier, string $requestId): void
    {
        DB::table('supplier_requests')->upsert(
            [[
                'request_id' => $requestId,
                'order_id' => $order->id,
                'supplier' => $supplier,
                'sku' => $order->sku,
                'state' => SupplierRequestState::InFlight->value,
                'attempts' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['request_id'],
            [
                'state' => SupplierRequestState::InFlight->value,
                'attempts' => DB::raw('supplier_requests.attempts + 1'),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Код получен. Всё, что делает заказ выданным, происходит в одной транзакции:
     * факт выдачи, статус, остаток, проводки.
     */
    private function finalize(Order $order, string $supplier, string $requestId, string $code): void
    {
        DB::transaction(function () use ($order, $supplier, $requestId, $code): void {
            $order = Order::where('id', $order->id)->lockForUpdate()->first();

            if (Delivery::where('order_id', $order->id)->exists()) {
                // Ответ поставщика опоздал: заказ закрыт другой выдачей.
                // Код не выбрасываем — он оплачен, идёт в сверку и возврат.
                UnclaimedCode::create([
                    'request_id' => $requestId,
                    'order_id' => $order->id,
                    'supplier' => $supplier,
                    'code' => $code,
                    'reason' => 'late_response_after_delivery',
                ]);

                Log::warning('delivery.blocked_duplicate', [
                    'event' => 'delivery.blocked_duplicate',
                    'order_id' => $order->id,
                    'request_id' => $requestId,
                    'supplier' => $supplier,
                    'outcome' => 'unclaimed',
                ]);

                return;
            }

            // PK по order_id + UNIQUE по code — последний рубеж exactly-once.
            Delivery::create([
                'order_id' => $order->id,
                'code' => $code,
                'supplier' => $supplier,
                'request_id' => $requestId,
            ]);

            $this->transitions->apply($order->id, [OrderStatus::Delivering], OrderStatus::Delivered, [
                'delivered_at' => now(),
            ]);

            // Витрина остатков. Уходить в минус не даёт CHECK, но условие
            // здесь нужно, чтобы не ловить исключение на рассинхроне счётчика.
            DB::table('product_stock')
                ->where('sku', $order->sku)
                ->where('available', '>', 0)
                ->update([
                    'available' => DB::raw('available - 1'),
                    'updated_at' => now(),
                ]);

            DB::table('supplier_requests')->where('request_id', $requestId)->update([
                'state' => SupplierRequestState::Succeeded->value,
                'code' => $code,
                'updated_at' => now(),
            ]);

            $this->ledger->recordDelivery($order, $requestId);

            Log::info('delivery.completed', [
                'event' => 'delivery.completed',
                'order_id' => $order->id,
                'request_id' => $requestId,
                'supplier' => $supplier,
                'outcome' => 'delivered',
            ]);
        });
    }

    /** Определённый отказ: кода нет. Состояние восстановимое. */
    private function reject(Order $order, string $requestId, SupplierOutcome $outcome): void
    {
        DB::transaction(function () use ($order, $requestId, $outcome): void {
            DB::table('supplier_requests')->where('request_id', $requestId)->update([
                'state' => SupplierRequestState::Failed->value,
                'error_reason' => $outcome->reason,
                'updated_at' => now(),
            ]);

            $this->transitions->apply($order->id, [OrderStatus::Delivering], OrderStatus::OutOfStock);
        });

        Log::warning('delivery.out_of_stock', [
            'event' => 'delivery.out_of_stock',
            'order_id' => $order->id,
            'request_id' => $requestId,
            'outcome' => 'out_of_stock',
            'reason' => $outcome->reason,
        ]);
    }

    /**
     * Неопределённость. Поставщик мог выдать код, мы этого не знаем.
     *
     * Этап 1 закрывает заказ в delivery_failed — восстановимое состояние.
     * Этап 3 заменит это ретраем с тем же request_id (он же probe) и уже
     * потом решением про фолбэк.
     */
    private function giveUpForNow(Order $order, string $requestId, SupplierOutcome $outcome): void
    {
        DB::transaction(function () use ($order, $requestId, $outcome): void {
            DB::table('supplier_requests')->where('request_id', $requestId)->update([
                'state' => SupplierRequestState::Unknown->value,
                'error_reason' => $outcome->reason,
                'updated_at' => now(),
            ]);

            $this->transitions->apply($order->id, [OrderStatus::Delivering], OrderStatus::DeliveryFailed);
        });

        Log::error('delivery.failed', [
            'event' => 'delivery.failed',
            'order_id' => $order->id,
            'request_id' => $requestId,
            'outcome' => 'unknown',
            'reason' => $outcome->reason,
        ]);
    }
}
