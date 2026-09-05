<?php

namespace App\Domain\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * Витрина каталога (этап 5).
 *
 * Два решения, ради которых класс и существует.
 *
 * 1. Остаток читается из product_stock — денормализованного счётчика,
 *    который обновляется в той же транзакции, что и выдача. Наивная
 *    альтернатива, count(*) по пулу ключей на каждый SKU, стоит O(число
 *    ключей) на каждый запрос витрины; на сотнях тысяч строк это десятки
 *    миллисекунд там, где нужны доли.
 *
 * 2. Пагинация keyset, а не offset: `where sku > :after order by sku limit N`
 *    стоит одинаково на первой странице и на тысячной, потому что индекс
 *    сразу позиционируется на нужное место. OFFSET 100000 честно читает и
 *    выбрасывает сто тысяч строк.
 */
class CatalogQuery
{
    public const MAX_LIMIT = 200;

    /**
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string, limit: int}
     */
    public function page(?string $type = null, ?string $after = null, int $limit = 50): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $rows = DB::table('products as p')
            ->join('product_stock as s', 's.sku', '=', 'p.sku')
            ->select('p.sku', 'p.name', 'p.type', 'p.price', 'p.currency', 'p.image', 's.available')
            ->where('p.is_active', true)
            ->when($type !== null, fn ($q) => $q->where('p.type', $type))
            ->when($after !== null, fn ($q) => $q->where('p.sku', '>', $after))
            ->orderBy('p.sku')
            // На одну строку больше запрошенного: так видно, есть ли следующая
            // страница, без отдельного count(*) по всей витрине.
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit);

        return [
            'items' => $items->map(fn (object $row): array => [
                'sku' => $row->sku,
                'name' => $row->name,
                'type' => $row->type,
                // Деньги строкой: клиенту незачем превращать их во float.
                'price' => (string) $row->price,
                'currency' => $row->currency,
                'image' => $row->image,
                'available' => (int) $row->available,
            ])->values()->all(),
            'next_cursor' => $hasMore ? (string) $items->last()->sku : null,
            'limit' => $limit,
        ];
    }
}
