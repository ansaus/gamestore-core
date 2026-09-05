<?php

namespace App\Http\Resources;

use App\Domain\Order\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $delivery = $this->delivery;

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            // Деньги отдаём строкой: json_decode на той стороне не должен
            // превращать 1290.00 во float.
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'delivery' => $delivery === null ? null : [
                'code' => $delivery->code,
                'supplier' => $delivery->supplier,
                'delivered_at' => $this->delivered_at?->toIso8601String(),
            ],
            'timeline' => $this->timeline(),
        ];
    }

    /**
     * Таймлайн собирается из отметок времени самого заказа: отдельной таблицы
     * истории в схеме нет. Статусы без своей колонки (delivering,
     * out_of_stock, ...) показываются один раз, по updated_at.
     *
     * @return list<array{status: string, at: ?string}>
     */
    private function timeline(): array
    {
        $points = [['status' => 'created', 'at' => $this->created_at]];

        if ($this->paid_at !== null) {
            $points[] = ['status' => 'paid', 'at' => $this->paid_at];
        }

        if ($this->delivered_at !== null) {
            $points[] = ['status' => 'delivered', 'at' => $this->delivered_at];
        }

        $current = $this->status->value;

        if (! in_array($current, array_column($points, 'status'), true)) {
            $points[] = ['status' => $current, 'at' => $this->updated_at];
        }

        return array_map(
            fn (array $p) => ['status' => $p['status'], 'at' => $p['at']?->toIso8601String()],
            $points,
        );
    }
}
