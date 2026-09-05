<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        | Код пришёл от поставщика, но заказу он уже не нужен: выдача по нему
        | состоялась раньше. Выбрасывать нельзя — за него заплачено, он идёт
        | в сверку и возврат поставщику.
        */
        Schema::create('unclaimed_codes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->text('request_id')->unique();
            $table->text('order_id');
            $table->text('supplier');
            $table->text('code');
            $table->text('reason');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unclaimed_codes');
    }
};
