<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        | Факт выдачи. Последний рубеж exactly-once:
        |   order_id PRIMARY KEY — на заказ не больше одной выдачи (I1);
        |   code UNIQUE          — один код не уйдёт в два заказа (I2).
        | Даже если прогорят все проверки в коде, БД вторую выдачу не примет.
        */
        Schema::create('deliveries', function (Blueprint $table) {
            $table->text('order_id')->primary();
            $table->text('code')->unique();
            $table->text('supplier');
            $table->text('request_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('order_id')->references('id')->on('orders');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
