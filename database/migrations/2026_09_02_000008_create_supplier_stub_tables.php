<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /*
    | Заглушка поставщика. Своя схема, а не свои таблицы рядом с доменом:
    | это чужая система, её состояние не должно смешиваться с нашим.
    |
    | Ключевое место — stub_issues.request_id PRIMARY KEY (I4): повторный
    | запрос с тем же request_id обязан вернуть ТОТ ЖЕ код. На этом стоит
    | весь сценарий «таймаут, но код уже выдан».
    */
    public function up(): void
    {
        $schema = $this->schema();

        DB::statement("create schema if not exists {$schema}");

        DB::statement("
            create table {$schema}.stub_keys (
                id          bigserial primary key,
                supplier    text not null,
                code        text not null unique,
                request_id  text unique,
                status      text not null default 'free',
                constraint stub_keys_supplier_check check (supplier in ('A', 'B')),
                constraint stub_keys_status_check check (status in ('free', 'issued')),
                constraint stub_keys_issued_check check (
                    (status = 'free' and request_id is null)
                    or (status = 'issued' and request_id is not null)
                )
            )
        ");

        // Свободные ключи поставщика — горячая выборка при выдаче
        // (FOR UPDATE SKIP LOCKED по этому индексу).
        DB::statement("
            create index stub_keys_free_idx on {$schema}.stub_keys (supplier, id)
                where status = 'free'
        ");

        DB::statement("
            create table {$schema}.stub_issues (
                request_id  text primary key,
                supplier    text not null,
                order_id    text not null,
                sku         text not null,
                code        text not null unique,
                created_at  timestamptz not null default now()
            )
        ");
    }

    public function down(): void
    {
        $schema = $this->schema();

        DB::statement("drop table if exists {$schema}.stub_issues");
        DB::statement("drop table if exists {$schema}.stub_keys");
        DB::statement("drop schema if exists {$schema}");
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
