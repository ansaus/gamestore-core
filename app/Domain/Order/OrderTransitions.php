<?php

namespace App\Domain\Order;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Единственное место, где меняется orders.status.
 *
 * Переход — всегда условный UPDATE ... WHERE status IN (...). Нулевой rowcount
 * это не ошибка, а проигранная гонка: кто-то уже перевёл заказ дальше.
 * Так финальный статус не откатывается даже под параллельными обработчиками (I5).
 */
class OrderTransitions
{
    /**
     * @param  list<OrderStatus>  $from  допустимые исходные статусы
     * @param  array<string, mixed>  $extra  дополнительные поля (paid_at, delivered_at, ...)
     * @return bool переход применён
     */
    public function apply(string $orderId, array $from, OrderStatus $to, array $extra = []): bool
    {
        foreach ($from as $source) {
            if (! $source->canTransitionTo($to)) {
                throw new LogicException(
                    "Недопустимый переход {$source->value} → {$to->value}"
                );
            }
        }

        $affected = DB::table('orders')
            ->where('id', $orderId)
            ->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, $from))
            ->update($extra + [
                'status' => $to->value,
                'updated_at' => now(),
            ]);

        return $affected > 0;
    }
}
