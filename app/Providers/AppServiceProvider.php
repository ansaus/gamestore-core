<?php

namespace App\Providers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Контракты в docs/TASK.md — плоские объекты, обёртка "data" в них не предусмотрена.
        JsonResource::withoutWrapping();
    }
}
