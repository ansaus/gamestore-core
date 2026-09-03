<?php

namespace App\Jobs;

use App\Domain\Delivery\RetrySchedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Фоновое доведение зависших заказов (SPEC §8), раз в минуту.
 *
 * Ничего «умного» не делает: находит оплаченные, но не выданные заказы и
 * прогоняет их через ту же DeliverOrderJob с теми же `request_id` и теми же
 * проверками. Повторный запуск для уже выданного заказа — штатный no-op,
 * на этом и стоит безопасность всей затеи.
 *
 * Перед отправкой в очередь заказу двигается `next_attempt_at` вперёд. Это
 * лизинг, а не косметика: без него следующий тик (через минуту) заберёт тот
 * же заказ, пока предыдущая джоба ещё стоит в очереди.
 */
class ReconcileStuckOrders implements ShouldQueue
{
    use Queueable;

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping(static::class))->dontRelease()];
    }

    public function handle(): void
    {
        $orders = $this->due();

        if ($orders === []) {
            return;
        }

        foreach ($orders as $order) {
            // Лизинг + бэкофф по числу циклов: следующий заход к этому заказу
            // будет вдвое позже предыдущего, до потолка.
            DB::table('orders')
                ->where('id', $order->id)
                ->update([
                    'next_attempt_at' => RetrySchedule::nextAttemptAt((int) $order->attempts + 1),
                    'updated_at' => now(),
                ]);

            DeliverOrderJob::dispatch($order->id)->afterCommit();

            Log::info('reconcile.finding', [
                'event' => 'reconcile.finding',
                'order_id' => $order->id,
                'outcome' => 'redelivery_scheduled',
                'attempt' => (int) $order->attempts,
                'status' => $order->status,
            ]);
        }

        Log::info('reconcile.stuck_orders', [
            'event' => 'reconcile.stuck_orders',
            'outcome' => 'dispatched',
            'count' => count($orders),
        ]);
    }

    /**
     * Заказы, до которых пора вернуться.
     *
     * `delivering` берём только состарившийся: свежий — это заказ, которым
     * прямо сейчас занят воркер, и торопить его незачем. Заказ без
     * `next_attempt_at` подхватывается сразу — так в очередь возвращаются те,
     * чья джоба потерялась вместе с упавшим воркером.
     *
     * @return list<object>
     */
    private function due(): array
    {
        $grace = (int) config('gamestore.reconcile.grace_seconds');

        return DB::select("
            select o.id, o.status, o.attempts
            from orders o
            left join deliveries d on d.order_id = o.id
            where d.order_id is null
              and o.attempts < ?
              and (o.next_attempt_at is null or o.next_attempt_at <= now())
              and (
                    o.status in ('paid', 'out_of_stock', 'delivery_failed')
                 or (o.status = 'delivering' and o.updated_at <= now() - make_interval(secs => ?))
              )
            order by o.paid_at
            limit ?
        ", [
            (int) config('gamestore.reconcile.max_attempts'),
            $grace,
            (int) config('gamestore.reconcile.batch'),
        ]);
    }
}
