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
        | Журнал вебхуков. Вся идемпотентность приёма платежа держится на
        | первичном ключе event_id + INSERT ... ON CONFLICT DO NOTHING (I3).
        |
        | FK на orders сознательно НЕТ: событие может прийти раньше заказа,
        | и такое событие мы обязаны сохранить, а не отвергнуть.
        */
        Schema::create('payment_events', function (Blueprint $table) {
            $table->text('event_id')->primary();
            $table->text('order_id');
            $table->text('status');                          // paid|failed
            $table->decimal('amount', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestampTz('occurred_at')->nullable();  // created_at из payload
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('applied_at')->nullable();
            $table->text('apply_result')->nullable();

            // Сумма события разошлась с orders.amount. Признак независимый от
            // apply_result: деньги пришли, событие применяем, но помечаем.
            $table->boolean('amount_mismatch')->default(false);

            $table->jsonb('payload');
        });

        DB::statement("
            alter table payment_events add constraint payment_events_status_check
                check (status in ('paid', 'failed'))
        ");

        // 'duplicate' здесь не хранится: дубль — это ответ на повторный вебхук,
        // существующая строка при этом не трогается.
        DB::statement("
            alter table payment_events add constraint payment_events_apply_result_check
                check (apply_result is null or apply_result in (
                    'applied', 'orphan', 'stale', 'ignored_terminal'
                ))
        ");

        // Неприменённые события по заказу — подхват сирот (этап 2).
        DB::statement('
            create index payment_events_pending_idx on payment_events (order_id)
                where applied_at is null
        ');

        // Расхождения по сумме — отчёт сверки (этап 4). Частичный индекс:
        // строк с mismatch единицы, полный индекс тут не нужен.
        DB::statement('
            create index payment_events_amount_mismatch_idx on payment_events (received_at)
                where amount_mismatch
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
