<?php

use App\Jobs\ReconcileOrphanEvents;
use Illuminate\Support\Facades\Schedule;

/*
| Расписание крутит контейнер scheduler (`php artisan schedule:work`).
*/

// Подхват событий, пришедших раньше заказа. Основной путь — транзакция
// создания заказа; это подстраховка на случай, когда заказ появился мимо неё.
Schedule::job(new ReconcileOrphanEvents)
    ->everyMinute()
    ->withoutOverlapping()
    ->name('reconcile-orphan-events');
