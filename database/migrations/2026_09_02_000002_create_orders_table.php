<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Номера публичных id заказов. Последовательность, а не max(id)+1.
        DB::statement('create sequence if not exists orders_public_id_seq');

        Schema::create('orders', function (Blueprint $table) {
            $table->text('id')->primary();              // ord_00123
            $table->text('sku');
            $table->decimal('amount', 12, 2);           // цена зафиксирована на момент создания
            $table->char('currency', 3);
            $table->text('status');
            $table->text('idempotency_key')->nullable()->unique();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('sku')->references('sku')->on('products');
            $table->index(['status', 'next_attempt_at'], 'orders_status_next_attempt_idx');
        });

        // Статус — закрытое множество на уровне БД: опечатка в коде не проедет.
        DB::statement("
            alter table orders add constraint orders_status_check check (status in (
                'created', 'paid', 'delivering', 'delivered',
                'payment_failed', 'out_of_stock', 'delivery_failed'
            ))
        ");
        DB::statement('alter table orders add constraint orders_amount_check check (amount >= 0)');

        // Привязываем последовательность к таблице: иначе migrate:fresh снесёт
        // orders, а счётчик номеров переживёт сброс и продолжит с прежнего места.
        DB::statement('alter sequence orders_public_id_seq owned by orders.id');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
        DB::statement('drop sequence if exists orders_public_id_seq');
    }
};
