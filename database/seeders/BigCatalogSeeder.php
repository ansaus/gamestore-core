<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Нагрузочный датасет: 5 000 SKU и 500 000 ключей (SPEC §9).
 *
 * Всё строится через generate_series прямо в Postgres. Гонять полмиллиона
 * строк через PHP означало бы минуты ожидания и мегабайты в памяти там, где
 * база справляется за секунды: данные никуда не едут, они уже на месте.
 *
 * Демо-каталог не трогаем — он нужен сценариям приёмки. Нагрузочные SKU
 * живут рядом с префиксом BULK-.
 */
class BigCatalogSeeder extends Seeder
{
    private const SKUS = 5_000;

    private const KEYS = 500_000;

    public function run(): void
    {
        $stub = config('gamestore.stub.schema');

        $this->command?->info('Генерируем '.number_format(self::SKUS, 0, '', ' ').' SKU...');

        DB::statement("
            insert into products (sku, name, type, price, currency, image, is_active)
            select 'BULK-'||lpad(i::text, 6, '0'),
                   'Нагрузочный товар #'||i,
                   (array['topup', 'key', 'subscription', 'giftcard'])[1 + (i % 4)],
                   (100 + (i % 900))::numeric(12,2),
                   'RUB',
                   null,
                   -- Каждый сотый неактивен: частичный индекс должен их отсекать,
                   -- и на плане это должно быть видно.
                   (i % 100) <> 0
            from generate_series(1, ?) i
            on conflict (sku) do nothing
        ", [self::SKUS]);

        // Витрина заполняется тем же числом, что и реальный пул ключей по SKU:
        // дальше счётчик живёт своей жизнью, уменьшаясь в транзакции выдачи.
        DB::statement("
            insert into product_stock (sku, available, updated_at)
            select p.sku, ?::int / ?::int, now()
            from products p
            where p.sku like 'BULK-%'
            on conflict (sku) do update set available = excluded.available, updated_at = now()
        ", [self::KEYS, self::SKUS]);

        $this->command?->info('Генерируем '.number_format(self::KEYS, 0, '', ' ').' ключей...');

        /*
         * Ключи раскладываются по SKU ровным слоем: 100 штук на товар.
         * Поставщик чередуется, чтобы у обоих был живой пул.
         */
        DB::statement("
            insert into {$stub}.stub_keys (supplier, code, request_id, status, sku)
            select case when i % 5 = 0 then 'B' else 'A' end,
                   'BULK-'||lpad(i::text, 7, '0')||'-'||substr(md5(i::text), 1, 8),
                   null,
                   'free',
                   'BULK-'||lpad((1 + (i - 1) / (?::int / ?::int))::text, 6, '0')
            from generate_series(1, ?) i
            on conflict (code) do nothing
        ", [self::KEYS, self::SKUS, self::KEYS]);

        // Планировщику нужна свежая статистика: без ANALYZE он будет считать,
        // что в таблице по-прежнему полсотни строк, и выберет не тот план.
        $this->command?->info('ANALYZE...');
        DB::statement('analyze products');
        DB::statement('analyze product_stock');
        DB::statement("analyze {$stub}.stub_keys");

        $products = DB::table('products')->where('sku', 'like', 'BULK-%')->count();
        $keys = DB::table("{$stub}.stub_keys")->whereNotNull('sku')->count();

        $this->command?->info("Готово: {$products} SKU, {$keys} ключей.");
    }
}
