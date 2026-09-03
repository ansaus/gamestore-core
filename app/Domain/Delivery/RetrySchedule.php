<?php

namespace App\Domain\Delivery;

use Carbon\CarbonImmutable;

/**
 * Когда возвращаться к недоведённому заказу.
 *
 * Экспоненциальный бэкофф по числу циклов (SPEC §8): первый повтор через
 * `unknown_retry_delay`, дальше вдвое дольше, до потолка. Одно место на всех,
 * чтобы выдача и watchdog не разъезжались в оценке «когда уже можно».
 */
final class RetrySchedule
{
    public static function delaySeconds(int $attempts): int
    {
        $base = max(1, (int) config('gamestore.supplier.unknown_retry_delay'));
        $cap = max($base, (int) config('gamestore.reconcile.max_backoff_seconds'));

        // 2^30 секунд в int влезет, но считать это незачем: потолок всё равно ниже.
        $exponent = min(max(0, $attempts - 1), 20);

        return (int) min($base * (2 ** $exponent), $cap);
    }

    public static function nextAttemptAt(int $attempts): CarbonImmutable
    {
        return now()->toImmutable()->addSeconds(self::delaySeconds($attempts));
    }
}
