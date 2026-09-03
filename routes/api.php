<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/catalog', [CatalogController::class, 'index']);

Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{id}', [OrderController::class, 'show']);

Route::post('/webhooks/payment', [PaymentWebhookController::class, 'store']);

/*
| Служебные ручки. Авторизации нет по условию задания (SPEC §0.3),
| в бою префикс закрывается на ingress.
*/
Route::prefix('admin')->group(function () {
    Route::get('/reconcile', [AdminController::class, 'reconcile']);
    Route::post('/orders/{id}/retry-delivery', [AdminController::class, 'retryDelivery']);
    Route::post('/stock/{sku}/refill', [AdminController::class, 'refillStock']);
});
