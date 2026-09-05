<?php

namespace App\Domain\Delivery;

use App\Domain\Catalog\CatalogCache;
use App\Domain\Ledger\Ledger;
use App\Domain\Order\Order;
use App\Domain\Order\OrderStatus;
use App\Domain\Order\OrderTransitions;
use App\Support\Supplier\SupplierClient;
use App\Support\Supplier\SupplierOutcome;
use App\Support\Supplier\SupplierOutcomeKind;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Выдача заказа.
 *
 * Структура метода подчинена инварианту I7: HTTP к поставщику идёт МЕЖДУ
 * транзакциями, а не внутри. Держать открытую транзакцию на время сетевого
 * вызова — это блокировка строки заказа на секунды и затык под нагрузкой.
 *
 * Главное правило этапа 3: фолбэк A→B разрешён ТОЛЬКО когда
 * состояние A определённое. Неопределённость (таймаут, 5xx) — не отказ:
 * поставщик мог выдать код, и уход к B списал бы второй ключ за тот же заказ.
 * В этом случае заказ остаётся в delivering и ждёт следующего цикла, где его
 * допытывают тем же request_id.
 */
class DeliveryService
{
    public function __construct(
        private readonly SupplierClient $client,
        private readonly OrderTransitions $transitions,
        private readonly Ledger $ledger,
        private readonly CatalogCache $catalogCache,
    ) {}

    public function deliver(string $orderId): void
    {
        $order = $this->beginDelivering($orderId);

        if ($order === null) {
            return;
        }

        $suppliers = array_values((array) config('gamestore.supplier.order'));

        foreach ($suppliers as $i => $supplier) {
            $requestId = SupplierRequest::idFor($order->id, $supplier);

            if ($i > 0) {
                Log::info('supplier.fallback', [
                    'event' => 'supplier.fallback',
                    'order_id' => $order->id,
                    'request_id' => $requestId,
                    'supplier' => $supplier,
                    'outcome' => 'fallback_from_'.strtolower((string) $suppliers[$i - 1]),
                ]);
            }

            $this->markInFlight($order, $supplier, $requestId);

            Log::info('delivery.started', [
                'event' => 'delivery.started',
                'order_id' => $order->id,
                'request_id' => $requestId,
                'supplier' => $supplier,
                'attempt' => $order->attempts,
            ]);

            // --- вне транзакции (I7). Внутри — до 3 попыток тем же request_id.
            $outcome = $this->client->issue($supplier, $requestId, $order->id, $order->sku);
            // ---------------------------------------------------------------

            if ($outcome->kind === SupplierOutcomeKind::Succeeded) {
                if ($this->finalize($order, $supplier, $requestId, $outcome)) {
                    // Остаток изменился — витрина больше не актуальна.
                    // Строго после коммита: инвалидировать то, чего может
                    // не оказаться в БД, — верный способ показать призрак.
                    $this->catalogCache->invalidate();
                }

                return;
            }

            if ($outcome->kind === SupplierOutcomeKind::Unknown) {
                // Таймаут ≠ отказ. Выйти отсюда к следующему поставщику
                // нельзя ни при каких обстоятельствах.
                $this->holdForRetry($order, $supplier, $requestId, $outcome);

                return;
            }

            // Определённый отказ: этот поставщик точно ничего не выдал,
            // ключ не потрачен, фолбэк безопасен.
            $this->recordOutcome($requestId, SupplierRequestState::Failed, $outcome);

            Log::warning('delivery.rejected', [
                'event' => 'delivery.rejected',
                'order_id' => $order->id,
                'request_id' => $requestId,
                'supplier' => $supplier,
                'outcome' => 'rejected',
                'reason' => $outcome->reason,
            ]);
        }

        // Все поставщики отказали определённо — кода нет ни у кого.
        $this->outOfStock($order);
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

            // Повторный заход в уже идущую выдачу — путь фонового доведения
            // для заказа, чей исход остался невыясненным. Статус менять не на
            // что: delivering и есть честное описание происходящего.
            if ($order->status === OrderStatus::Delivering) {
                DB::table('orders')->where('id', $orderId)->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'next_attempt_at' => null,
                    'updated_at' => now(),
                ]);

                return $order->refresh();
            }

            // Повторный запуск для уже выданного заказа — штатный no-op,
            // на этом стоит безопасность фонового доведения.
            $startable = [OrderStatus::Paid, OrderStatus::OutOfStock, OrderStatus::DeliveryFailed];

            if (! in_array($order->status, $startable, true)) {
                return null;
            }

            $this->transitions->apply($orderId, [$order->status], OrderStatus::Delivering, [
                'attempts' => DB::raw('attempts + 1'),
                // Цикл начался: заявка на следующий больше не актуальна.
                // Новую поставит holdForRetry, если исход останется неясным.
                'next_attempt_at' => null,
            ]);

            return $order->refresh();
        });
    }

    /** Заявка к поставщику. request_id детерминирован, строка одна на пару (заказ, поставщик). */
    private function markInFlight(Order $order, string $supplier, string $requestId): void
    {
        DB::table('supplier_requests')->upsert(
            [[
                'request_id' => $requestId,
                'order_id' => $order->id,
                'supplier' => $supplier,
                'sku' => $order->sku,
                'state' => SupplierRequestState::InFlight->value,
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['request_id'],
            ['state' => SupplierRequestState::InFlight->value, 'updated_at' => now()],
        );
    }

    /** Итог обращения. attempts копится по всем циклам — это счётчик HTTP-попыток. */
    private function recordOutcome(
        string $requestId,
        SupplierRequestState $state,
        SupplierOutcome $outcome,
    ): void {
        DB::table('supplier_requests')->where('request_id', $requestId)->update([
            'state' => $state->value,
            'code' => $outcome->code,
            'error_reason' => $outcome->reason,
            'attempts' => DB::raw('attempts + '.$outcome->attempts),
            'updated_at' => now(),
        ]);
    }

    /**
     * Код получен. Всё, что делает заказ выданным, происходит в одной транзакции:
     * факт выдачи, статус, остаток, проводки.
     */
    private function finalize(
        Order $order,
        string $supplier,
        string $requestId,
        SupplierOutcome $outcome,
    ): bool {
        $code = (string) $outcome->code;

        return DB::transaction(function () use ($order, $supplier, $requestId, $outcome, $code): bool {
            $order = Order::where('id', $order->id)->lockForUpdate()->first();

            if (Delivery::where('order_id', $order->id)->exists()) {
                // Ответ поставщика опоздал: заказ закрыт другой выдачей.
                // Код не выбрасываем — он оплачен, идёт в сверку и возврат.
                $this->parkUnclaimed($order, $supplier, $requestId, $code, 'late_response_after_delivery');

                Log::warning('delivery.blocked_duplicate', [
                    'event' => 'delivery.blocked_duplicate',
                    'order_id' => $order->id,
                    'request_id' => $requestId,
                    'supplier' => $supplier,
                    'outcome' => 'unclaimed',
                ]);

                return false;
            }

            try {
                // PK по order_id + UNIQUE по code — последний рубеж exactly-once.
                //
                // Вложенная транзакция здесь не для атомарности, а ради
                // SAVEPOINT: в Postgres нарушение ограничения отменяет всю
                // транзакцию целиком, и без точки сохранения писать после
                // catch было бы уже некуда.
                DB::transaction(fn () => Delivery::create([
                    'order_id' => $order->id,
                    'code' => $code,
                    'supplier' => $supplier,
                    'request_id' => $requestId,
                ]));
            } catch (UniqueConstraintViolationException) {
                // Поставщик выдал код, уже проданный другому заказу (I2).
                // Это его баг, но продать один ключ дважды мы не имеем права:
                // выдачу не создаём, код паркуем в сверку, орём в лог.
                $this->parkUnclaimed($order, $supplier, $requestId, $code, 'code_already_sold');

                DB::table('supplier_requests')->where('request_id', $requestId)->update([
                    'state' => SupplierRequestState::Failed->value,
                    'error_reason' => 'code_already_sold',
                    'updated_at' => now(),
                ]);

                // Заказ остаётся delivering — доведение отдаём watchdog'у.
                DB::table('orders')
                    ->where('id', $order->id)
                    ->where('status', OrderStatus::Delivering->value)
                    ->update([
                        'next_attempt_at' => RetrySchedule::nextAttemptAt($order->attempts),
                        'updated_at' => now(),
                    ]);

                Log::error('delivery.code_conflict', [
                    'event' => 'delivery.code_conflict',
                    'order_id' => $order->id,
                    'request_id' => $requestId,
                    'supplier' => $supplier,
                    'outcome' => 'code_already_sold',
                ]);

                return false;
            }

            $this->transitions->apply($order->id, [OrderStatus::Delivering], OrderStatus::Delivered, [
                'delivered_at' => now(),
                'next_attempt_at' => null,
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

            $this->recordOutcome($requestId, SupplierRequestState::Succeeded, $outcome);

            $this->ledger->recordDelivery($order, $requestId);

            Log::info('delivery.completed', [
                'event' => 'delivery.completed',
                'order_id' => $order->id,
                'request_id' => $requestId,
                'supplier' => $supplier,
                'outcome' => 'delivered',
            ]);

            return true;
        });
    }

    /**
     * Исход не выяснен после всех попыток.
     *
     * Заказ НЕ провален и НЕ уходит к другому поставщику: мы не знаем, выдал
     * ли этот код. Остаёмся в delivering, ставим next_attempt_at — следующий
     * цикл допытается тем же request_id. Осознанный выбор: лучше задержать
     * выдачу, чем задвоить.
     *
     * Терпение не бесконечно: после max_delivery_cycles циклов сдаёмся в
     * delivery_failed — восстановимое состояние, но уже с алертом в логах.
     */
    private function holdForRetry(
        Order $order,
        string $supplier,
        string $requestId,
        SupplierOutcome $outcome,
    ): void {
        $exhausted = $order->attempts >= (int) config('gamestore.supplier.max_delivery_cycles');
        $nextAttemptAt = RetrySchedule::nextAttemptAt($order->attempts);

        DB::transaction(function () use ($order, $requestId, $outcome, $exhausted, $nextAttemptAt): void {
            $this->recordOutcome($requestId, SupplierRequestState::Unknown, $outcome);

            if ($exhausted) {
                $this->transitions->apply($order->id, [OrderStatus::Delivering], OrderStatus::DeliveryFailed, [
                    'next_attempt_at' => $nextAttemptAt,
                ]);

                return;
            }

            // Статус не трогаем: delivering — честное описание того, что
            // происходит. Заказ не провален, исход просто ещё не известен.
            DB::table('orders')
                ->where('id', $order->id)
                ->where('status', OrderStatus::Delivering->value)
                ->update(['next_attempt_at' => $nextAttemptAt, 'updated_at' => now()]);
        });

        $context = [
            'order_id' => $order->id,
            'request_id' => $requestId,
            'supplier' => $supplier,
            'attempt' => $order->attempts,
            'outcome' => 'unknown',
            'reason' => $outcome->reason,
            'next_attempt_at' => $nextAttemptAt->toIso8601String(),
        ];

        if ($exhausted) {
            Log::error('delivery.failed', ['event' => 'delivery.failed'] + $context);

            return;
        }

        Log::warning('delivery.deferred', ['event' => 'delivery.deferred'] + $context);
    }

    /**
     * Код есть, а отдать его этому заказу нельзя. Не выбрасываем: он оплачен,
     * его место — в сверке и возврате. insertOrIgnore, потому что повторный
     * заход по тому же request_id упрётся в UNIQUE и это не ошибка.
     */
    private function parkUnclaimed(
        Order $order,
        string $supplier,
        string $requestId,
        string $code,
        string $reason,
    ): void {
        DB::table('unclaimed_codes')->insertOrIgnore([[
            'request_id' => $requestId,
            'order_id' => $order->id,
            'supplier' => $supplier,
            'code' => $code,
            'reason' => $reason,
            'created_at' => now(),
        ]]);
    }

    /** Ни один поставщик не дал кода, и все отказали определённо. Состояние восстановимое. */
    private function outOfStock(Order $order): void
    {
        $nextAttemptAt = RetrySchedule::nextAttemptAt($order->attempts);

        DB::transaction(function () use ($order, $nextAttemptAt): void {
            $this->transitions->apply($order->id, [OrderStatus::Delivering], OrderStatus::OutOfStock, [
                'next_attempt_at' => $nextAttemptAt,
            ]);
        });

        Log::warning('delivery.out_of_stock', [
            'event' => 'delivery.out_of_stock',
            'order_id' => $order->id,
            'outcome' => 'out_of_stock',
            'attempt' => $order->attempts,
            'next_attempt_at' => $nextAttemptAt->toIso8601String(),
        ]);
    }
}
