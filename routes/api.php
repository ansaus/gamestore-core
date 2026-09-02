<?php

use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{id}', [OrderController::class, 'show']);

Route::post('/webhooks/payment', [PaymentWebhookController::class, 'store']);

/*
| Дальше по SPEC:
|   GET  /catalog                          — этап 5
|   GET  /admin/reconcile                  — этап 4
|   POST /admin/orders/{id}/retry-delivery — этап 4
|   POST /admin/stock/{sku}/refill         — этап 4
*/
