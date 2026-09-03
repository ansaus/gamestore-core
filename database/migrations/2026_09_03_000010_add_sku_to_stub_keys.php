<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /*
    | Привязка ключа к товару.
    |
    | До этого пул заглушки был общим на поставщика: любой свободный ключ
    | годился любому заказу. Для 50 демо-ключей это упрощение безобидно, но
    | на нагрузочном датасете оно ломает саму постановку задачи этапа 5 —
    | «сколько ключей есть по этому SKU» без sku на ключе не спросить.
    |
    | Колонка nullable, и NULL означает «универсальный ключ». Демо-пул из
    | docs/keys.json остаётся весь в NULL, поэтому поведение сценариев 1–6
    | не меняется ни на шаг: выбор ключа по-прежнему находит их все.
    */
    public function up(): void
    {
        $schema = $this->schema();

        DB::statement("alter table {$schema}.stub_keys add column sku text");

        // Горячая выборка при выдаче: свободный ключ этого поставщика,
        // подходящий этому SKU. FOR UPDATE SKIP LOCKED идёт по нему же.
        DB::statement("
            create index stub_keys_free_sku_idx on {$schema}.stub_keys (supplier, sku, id)
                where status = 'free'
        ");
    }

    public function down(): void
    {
        $schema = $this->schema();

        DB::statement("drop index if exists {$schema}.stub_keys_free_sku_idx");
        DB::statement("alter table {$schema}.stub_keys drop column if exists sku");
    }

    private function schema(): string
    {
        $schema = (string) config('gamestore.stub.schema', 'stub');

        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $schema)) {
            throw new InvalidArgumentException("Недопустимое имя схемы заглушки: {$schema}");
        }

        return $schema;
    }
};
