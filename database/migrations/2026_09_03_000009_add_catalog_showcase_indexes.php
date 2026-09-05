<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /*
    | Индексы витрины (этап 5).
    |
    | Оба частичные по is_active: неактивные товары витрина не показывает
    | никогда, и держать их в индексе незачем. Оба заканчиваются на sku —
    | это ключ keyset-пагинации, и по нему же идёт ORDER BY, поэтому
    | сортировка достаётся бесплатно вместе синдексом.
    */
    public function up(): void
    {
        // Витрина с фильтром по типу: where type = ? and sku > ? order by sku.
        DB::statement('
            create index products_active_type_sku_idx on products (type, sku)
                where is_active
        ');

        // Витрина без фильтра: where sku > ? order by sku. По первичному ключу
        // это тоже Index Scan, но с проверкой is_active на каждой строке;
        // частичный индекс убирает и её.
        DB::statement('
            create index products_active_sku_idx on products (sku)
                where is_active
        ');
    }

    public function down(): void
    {
        DB::statement('drop index if exists products_active_type_sku_idx');
        DB::statement('drop index if exists products_active_sku_idx');
    }
};
