<?php

namespace App\Domain\Stub;

use Illuminate\Support\Facades\Cache;

/**
 * Настройки поведения заглушки.
 *
 * `force` даёт детерминизм: тест, зависящий от mt_rand, флакает. `*_rate`
 * оставлены для «случайного» режима из задания.
 */
final class StubConfig
{
    public const SUPPLIERS = ['A', 'B'];

    private const CONFIG_KEY = 'stub:config:';

    private const CALLS_KEY = 'stub:calls:';

    public const FORCE_MODES = ['none', 'timeout_after_issue', 'http_500', 'out_of_stock'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'fail_rate' => 0.0,
            'timeout_rate' => 0.0,
            'latency_ms' => 0,
            'force' => 'none',
            // Сколько «висеть» после выдачи. Должно быть заметно больше
            // SUPPLIER_TIMEOUT, иначе таймаут у клиента не наступит.
            'timeout_sleep_ms' => 5_000,
        ];
    }

    /** @return array<string, mixed> */
    public static function for(string $supplier): array
    {
        return Cache::get(self::CONFIG_KEY.strtoupper($supplier), self::defaults());
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function set(string $supplier, array $values): array
    {
        $merged = array_merge(self::for($supplier), $values);
        Cache::forever(self::CONFIG_KEY.strtoupper($supplier), $merged);

        return $merged;
    }

    public static function countCall(string $supplier): void
    {
        $key = self::CALLS_KEY.strtoupper($supplier);
        Cache::forever($key, self::calls($supplier) + 1);
    }

    public static function calls(string $supplier): int
    {
        return (int) Cache::get(self::CALLS_KEY.strtoupper($supplier), 0);
    }

    public static function reset(): void
    {
        foreach (self::SUPPLIERS as $supplier) {
            Cache::forget(self::CONFIG_KEY.$supplier);
            Cache::forget(self::CALLS_KEY.$supplier);
        }
    }
}
