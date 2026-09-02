<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            $role = config('app.role');

            // Магазин и заглушка поставщика живут в одной кодовой базе,
            // но в разных контейнерах: APP_ROLE решает, что поднимать.
            if (in_array($role, ['app', 'all'], true)) {
                Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
            }

            if (in_array($role, ['stub', 'all'], true)) {
                Route::middleware('api')->group(base_path('routes/stub.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
