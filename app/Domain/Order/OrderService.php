<?php

namespace App\Domain\Order;

use App\Domain\Catalog\Product;
use App\Domain\Payment\PaymentEventProcessor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    public function __construct(
        private readonly PaymentEventProcessor $payments,
    ) {}

    /**
     * Создаёт заказ. Цена фиксируется на момент создания и дальше не пересчитывается.
     *
     * @return array{0: Order, 1: bool} заказ и признак «создан именно сейчас»
     */
    public function create(Product $product, ?string $idempotencyKey): array
    {
        // Быстрый путь. Правду про уникальность говорит не он, а UNIQUE ниже.
        if ($idempotencyKey !== null) {
            $existing = Order::where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return [$existing, false];
            }
        }

        try {
            $order = DB::transaction(function () use ($product, $idempotencyKey): Order {
                $order = Order::create([
                    'id' => $this->nextId(),
                    'sku' => $product->sku,
                    'amount' => $product->price,
                    'currency' => $product->currency,
                    'status' => OrderStatus::Created,
                    'idempotency_key' => $idempotencyKey,
                ]);

                Log::info('order.created', [
                    'event' => 'order.created',
                    'order_id' => $order->id,
                    'sku' => $order->sku,
                    'amount' => (string) $order->amount,
                    'currency' => $order->currency,
                ]);

                // Оплата могла прийти раньше заказа. Подхват — в той же
                // транзакции, что и вставка: снаружи заказ не может появиться
                // без применённых к нему событий. Ответ клиенту сразу покажет
                // paid, а не created, который через миг станет paid.
                $this->payments->applyPending($order->id);

                return $order->refresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            // Два параллельных создания с одним Idempotency-Key: проигравший
            // забирает заказ победителя, а не создаёт второй.
            if ($idempotencyKey === null) {
                throw $e;
            }

            return [Order::where('idempotency_key', $idempotencyKey)->firstOrFail(), false];
        }

        return [$order, true];
    }

    /** Публичный номер заказа: ord_00123. Последовательность Postgres, не max(id)+1. */
    private function nextId(): string
    {
        $next = DB::selectOne("select nextval('orders_public_id_seq') as n")->n;

        return config('gamestore.order_id_prefix').str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
