<?php

use App\Http\Controllers\Stub\StubAdminController;
use App\Http\Controllers\Stub\SupplierStubController;
use Illuminate\Support\Facades\Route;

/*
| Заглушка поставщика. Отдельный контейнер (см. docker-compose.yml),
| чтобы таймауты ловились по сети, а не подменой класса в контейнере DI.
*/

Route::post('/supplier/{supplier}/issue', [SupplierStubController::class, 'issue']);

Route::post('/stub/config', [StubAdminController::class, 'store']);
Route::get('/stub/state', [StubAdminController::class, 'state']);
Route::post('/stub/reset', [StubAdminController::class, 'reset']);
