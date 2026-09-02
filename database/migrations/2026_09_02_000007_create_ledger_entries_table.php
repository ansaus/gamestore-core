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
        | Журнал денежных движений, двойная запись: sum(debit) = sum(credit)
        | глобально и по заказу (I6).
        |
        | unique (ref_type, ref_id, account, direction) — повторное применение
        | того же события не задвоит проводку. Это и есть защита от повторов
        | на уровне БД, а не «проверим, не писали ли мы уже».
        */
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->text('order_id');
            $table->text('account');                // customer|merchant_revenue|cogs|inventory
            $table->text('direction');              // debit|credit
            $table->decimal('amount', 12, 2);
            $table->text('ref_type');               // payment_event|delivery
            $table->text('ref_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['ref_type', 'ref_id', 'account', 'direction']);
            $table->index('order_id', 'ledger_entries_order_idx');
        });

        DB::statement('alter table ledger_entries add constraint ledger_entries_amount_check check (amount > 0)');
        DB::statement("
            alter table ledger_entries add constraint ledger_entries_direction_check
                check (direction in ('debit', 'credit'))
        ");
        DB::statement("
            alter table ledger_entries add constraint ledger_entries_account_check
                check (account in ('customer', 'merchant_revenue', 'cogs', 'inventory'))
        ");
        DB::statement("
            alter table ledger_entries add constraint ledger_entries_ref_type_check
                check (ref_type in ('payment_event', 'delivery'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
