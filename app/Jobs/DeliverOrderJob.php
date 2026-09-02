<?php

namespace App\Jobs;

use App\Domain\Delivery\DeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class DeliverOrderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $orderId) {}

    /**
     * Один заказ — один обработчик выдачи в моменте: два воркера не должны
     * параллельно ходить к поставщику по одному и тому же заказу.
     *
     * Это оптимизация, а не гарантия: настоящая защита от двойной выдачи —
     * deliveries.order_id PRIMARY KEY.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->orderId))->dontRelease()];
    }

    public function handle(DeliveryService $delivery): void
    {
        $delivery->deliver($this->orderId);
    }
}
