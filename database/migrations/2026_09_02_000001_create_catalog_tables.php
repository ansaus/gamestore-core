<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->text('sku')->primary();
            $table->text('name');
            $table->text('type');
            $table->decimal('price', 12, 2);
            $table->char('currency', 3)->default('RUB');
            $table->text('image')->nullable();
            $table->boolean('is_active')->default(true);
        });

        DB::statement("
            alter table products add constraint products_type_check
                check (type in ('topup', 'key', 'subscription', 'giftcard'))
        ");
        DB::statement('alter table products add constraint products_price_check check (price >= 0)');

        // Витрина остатков: денормализованный счётчик, чтобы каталог не считал
        // count(*) по ключам на каждый SKU (этап 5).
        Schema::create('product_stock', function (Blueprint $table) {
            $table->text('sku')->primary();
            $table->integer('available')->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('sku')->references('sku')->on('products');
        });

        // I-остаток: продать больше, чем есть, нельзя — это ограничение БД, не проверка в коде.
        DB::statement('alter table product_stock add constraint product_stock_available_check check (available >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock');
        Schema::dropIfExists('products');
    }
};
