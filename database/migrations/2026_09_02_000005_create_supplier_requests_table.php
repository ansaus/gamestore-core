<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        | Обращения к поставщикам. request_id детерминирован (req_{order}_{supplier})
        | и НЕ меняется между попытками — только так повтор после таймаута
        | возвращает тот же код, а не выписывает второй (этап 3).
        */
        Schema::create('supplier_requests', function (Blueprint $table) {
            $table->text('request_id')->primary();
            $table->text('order_id');
            $table->text('supplier');
            $table->text('sku');
            $table->text('state');                      // in_flight|succeeded|failed|unknown
            $table->text('code')->nullable();
            $table->text('error_reason')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('order_id')->references('id')->on('orders');
            $table->unique(['order_id', 'supplier']);
        });

        DB::statement("
            alter table supplier_requests add constraint supplier_requests_state_check
                check (state in ('in_flight', 'succeeded', 'failed', 'unknown'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_requests');
    }
};
