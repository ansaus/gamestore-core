<?php

use App\Jobs\ReconcileOrphanEvents;
use App\Jobs\ReconcileStuckOrders;
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

// Доведение заказов, оплаченных но не выданных: та же джоба выдачи, те же
// request_id, те же проверки. Повтор для выданного заказа — no-op.
Schedule::job(new ReconcileStuckOrders)
    ->everyMinute()
    ->withoutOverlapping()
    ->name('reconcile-stuck-orders');
