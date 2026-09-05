<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('docs/catalog.json');
        $catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! isset($catalog['products']) || $catalog['products'] === []) {
            throw new RuntimeException("Каталог пуст: {$path}");
        }

        // Цены грузим как есть, в рублях. Никаких пересчётов на границе:
        // ровно это число потом сверяется с суммой из вебхука.
        $products = array_map(fn (array $p) => [
            'sku' => $p['sku'],
            'name' => $p['name'],
            'type' => $p['type'],
            'price' => $p['price'],
            'currency' => $p['currency'],
            'image' => $p['image'] ?? null,
            'is_active' => true,
        ], $catalog['products']);

        DB::table('products')->upsert($products, ['sku'], ['name', 'type', 'price', 'currency', 'image', 'is_active']);

        /*
         * Витрина остатков.
         *
         * Пул ключей у заглушки общий на поставщика, а не на SKU, поэтому
         * точного «остатка по SKU» не существует. Стартуем от размера пула:
         * витрина допускает eventual consistency, точная правда проверяется
         * в момент выдачи, а расхождение приводит к восстановимому
         * out_of_stock, а не к продаже воздуха.
         */
        $poolSize = array_sum(config('gamestore.stub.key_split'));

        DB::table('product_stock')->upsert(
            array_map(fn (array $p) => [
                'sku' => $p['sku'],
                'available' => $poolSize,
                'updated_at' => now(),
            ], $products),
            ['sku'],
            ['available', 'updated_at'],
        );

        $this->command?->info('Каталог: '.count($products).' SKU, остаток '.$poolSize.' на каждый.');
    }
}
