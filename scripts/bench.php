<?php

/**
 * Этап 5: план выполнения витрины до и после (SPEC §9).
 *
 * Сравниваются три пары запросов на нагрузочном датасете:
 *
 *   1. остаток агрегатом по пулу ключей   против  чтения счётчика product_stock;
 *   2. OFFSET на глубокой странице         против  keyset-пагинации;
 *   3. фильтр по типу без частичного индекса и с ним.
 *
 * Печатается EXPLAIN (ANALYZE, BUFFERS) целиком: цифры в README должны быть
 * воспроизводимы, а не переписаны на глаз.
 *
 *   make bench
 *   php scripts/bench.php --runs=5
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/harness.php';

$opts = parseArgs($argv);
$runs = max(1, (int) ($opts['runs'] ?? 5));
$limit = max(1, (int) ($opts['limit'] ?? 50));
$stub = config('gamestore.stub.schema');

$products = DB::table('products')->count();
$keys = DB::table("{$stub}.stub_keys")->count();

if ($products < 1000 || $keys < 100_000) {
    fail("датасет слишком мал: {$products} SKU, {$keys} ключей. Сначала: make seed SCALE=big");
}

printf("\n  Датасет: %s SKU, %s ключей. Прогонов на запрос: %d.\n",
    number_format($products, 0, '', ' '), number_format($keys, 0, '', ' '), $runs);

// Глубокая страница: середина каталога.
$deepOffset = (int) ($products / 2);
$deepCursor = (string) DB::table('products')->where('is_active', true)
    ->orderBy('sku')->skip($deepOffset)->limit(1)->value('sku');

/** @var list<array{title: string, note: string, sql: string, bindings: list<mixed>}> $cases */
$cases = [
    [
        'title' => 'ДО — остаток агрегатом по пулу ключей',
        'note' => 'count(*) на каждый SKU: стоимость растёт с размером пула, а не витрины',
        'sql' => "
            select p.sku, p.name, p.type, p.price, p.currency, count(k.id) as available
            from products p
            left join {$stub}.stub_keys k on k.sku = p.sku and k.status = 'free'
            where p.is_active
            group by p.sku, p.name, p.type, p.price, p.currency
            order by p.sku
            limit ?
        ",
        'bindings' => [$limit],
    ],
    [
        'title' => 'ПОСЛЕ — остаток из счётчика product_stock',
        'note' => 'то же самое одним join по первичным ключам, без агрегации',
        'sql' => '
            select p.sku, p.name, p.type, p.price, p.currency, s.available
            from products p
            join product_stock s on s.sku = p.sku
            where p.is_active
            order by p.sku
            limit ?
        ',
        'bindings' => [$limit],
    ],
    [
        'title' => "ДО — глубокая страница через OFFSET {$deepOffset}",
        'note' => 'читает и выбрасывает все строки до нужной',
        'sql' => '
            select p.sku, s.available
            from products p
            join product_stock s on s.sku = p.sku
            where p.is_active
            order by p.sku
            limit ? offset ?
        ',
        'bindings' => [$limit, $deepOffset],
    ],
    [
        'title' => 'ПОСЛЕ — та же страница через keyset',
        'note' => 'индекс позиционируется сразу: стоимость не зависит от номера страницы',
        'sql' => '
            select p.sku, s.available
            from products p
            join product_stock s on s.sku = p.sku
            where p.is_active and p.sku > ?
            order by p.sku
            limit ?
        ',
        'bindings' => [$deepCursor, $limit],
    ],
    [
        'title' => 'Фильтр по типу — частичный индекс products(type, sku) where is_active',
        'note' => 'фильтр и сортировка закрыты одним индексом',
        'sql' => "
            select p.sku, s.available
            from products p
            join product_stock s on s.sku = p.sku
            where p.is_active and p.type = 'key' and p.sku > ?
            order by p.sku
            limit ?
        ",
        'bindings' => [$deepCursor, $limit],
    ],
];

foreach ($cases as $case) {
    printf("\n%s\n  %s\n  %s\n%s\n",
        str_repeat('=', 78), $case['title'], $case['note'], str_repeat('=', 78));

    $timings = [];

    // Первый прогон прогревает кеш страниц — его в статистику не берём,
    // иначе меряем состояние диска, а не план запроса.
    explain($case['sql'], $case['bindings']);

    for ($i = 0; $i < $runs; $i++) {
        $timings[] = measure($case['sql'], $case['bindings']);
    }

    sort($timings);

    printf("\n  %d прогонов: медиана %.3f мс, min %.3f, max %.3f\n",
        $runs, $timings[intdiv(count($timings), 2)], $timings[0], $timings[count($timings) - 1]);

    echo "\n";
    foreach (explode("\n", trim(explain($case['sql'], $case['bindings']))) as $line) {
        echo '  '.$line."\n";
    }
}

echo "\n";
exit(0);

/** @param list<mixed> $bindings */
function explain(string $sql, array $bindings): string
{
    $rows = DB::select('explain (analyze, buffers) '.$sql, $bindings);

    return implode("\n", array_map(static fn (object $r): string => $r->{'QUERY PLAN'}, $rows));
}

/**
 * @param  list<mixed>  $bindings
 * @return float миллисекунды
 */
function measure(string $sql, array $bindings): float
{
    $startedAt = hrtime(true);
    DB::select($sql, $bindings);

    return (hrtime(true) - $startedAt) / 1_000_000;
}
