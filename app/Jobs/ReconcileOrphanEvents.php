<?php

namespace App\Jobs;

use App\Domain\Payment\PaymentEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Второй механизм подхвата сирот (SPEC §5.3).
 *
 * Первый — в OrderService, в транзакции создания заказа. Этот нужен на случай,
 * когда заказ появился мимо того пути: восстановление из бэкапа, ручная правка,
 * будущий импорт. Механизм ровно один и тот же, applyPending(), поэтому
 * пересечение двух подхватов безопасно.
 *
 * Событие по заказу, которого нет и не будет, сюда не попадает: join с orders
 * отсекает вечных сирот, чтобы задача не перебирала их каждую минуту. Их место —
 * в отчёте сверки (этап 4), а не в очереди на применение.
 */
class ReconcileOrphanEvents implements ShouldQueue
{
    use Queueable;

    /** Потолок на один проход: задача идёт раз в минуту, разгребать очередь ей некуда спешить. */
    private const BATCH = 200;

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping(static::class))->dontRelease()];
    }

    public function handle(PaymentEventProcessor $processor): void
    {
        $orderIds = DB::table('payment_events as e')
            ->join('orders as o', 'o.id', '=', 'e.order_id')
            ->whereNull('e.applied_at')
            ->orderBy('e.order_id')
            ->distinct()
            ->limit(self::BATCH)
            ->pluck('e.order_id');

        if ($orderIds->isEmpty()) {
            return;
        }

        $applied = 0;

        foreach ($orderIds as $orderId) {
            $applied += $processor->applyPending((string) $orderId);
        }

        Log::info('reconcile.orphan_events', [
            'event' => 'reconcile.orphan_events',
            'orders' => $orderIds->count(),
            'outcome' => 'applied',
            'count' => $applied,
        ]);
    }
}
