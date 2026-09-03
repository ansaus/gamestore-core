<?php

namespace App\Domain\Catalog;

use Illuminate\Support\Facades\Cache;

/**
 * Кеш витрины в Redis.
 *
 * Инвалидация — через счётчик версии в ключе, а не через перебор и удаление
 * ключей: страниц у витрины много (типы × курсоры × лимиты), и обойти их все
 * на каждой выдаче дороже, чем сменить одно число. Старые записи никто больше
 * не спросит, и они истекают сами.
 *
 * Витрина остатков допускает eventual consistency: точная правда проверяется
 * в момент выдачи, а расхождение приводит к восстановимому out_of_stock, а не
 * к продаже воздуха (SPEC §9).
 */
class CatalogCache
{
    private const VERSION_KEY = 'catalog:version';

    /** @param array<string, mixed> $params */
    public function key(array $params): string
    {
        return 'catalog:v'.$this->version().':'.md5(json_encode($params, JSON_THROW_ON_ERROR));
    }

    public function ttl(): int
    {
        return (int) config('gamestore.catalog.cache_ttl');
    }

    public function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 0);
    }

    /** Остаток изменился — предыдущие страницы витрины больше не актуальны. */
    public function invalidate(): void
    {
        // add + increment: на пустом ключе increment у некоторых стораджей
        // (в том числе array в тестах) возвращает false и ничего не делает.
        Cache::add(self::VERSION_KEY, 0);
        Cache::increment(self::VERSION_KEY);
    }
}
