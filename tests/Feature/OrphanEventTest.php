<?php

use App\Domain\Ledger\LedgerEntry;
use App\Domain\Order\Order;
use App\Domain\Order\OrderStatus;
use App\Domain\Payment\PaymentEvent;
use App\Domain\Payment\PaymentEventProcessor;
use App\Jobs\DeliverOrderJob;
use App\Jobs\ReconcileOrphanEvents;
use Illuminate\Support\Facades\Queue;

/*
| Сценарий приёмки 3 (docs/TASK.md): вебхук пришёл раньше заказа или не по порядку.
| Выдача здесь не проверяется — ей посвящён DeliveryTest.
*/

beforeEach(function () {
    seedShop();
    Queue::fake();
});

/** Событие по заказу, которого ещё нет. */
function orphanEvent(string $orderId, array $overrides = []): array
{
    $payload = webhookPayload($orderId, $overrides);

    test()->postJson('/api/webhooks/payment', $payload)
        ->assertOk()
        ->assertJsonPath('result', 'orphan');

    return $payload;
}

it('подхватывает осиротевшую оплату при создании заказа', function () {
    // Заказа ещё нет — событие ложится сиротой и ждёт его.
    $id = nextOrderId();
    $payload = orphanEvent($id);

    expect(PaymentEvent::find($payload['event_id'])->applied_at)->toBeNull();

    $response = $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'])->assertCreated();

    // Клиент видит paid сразу: подхват был в той же транзакции, что и вставка.
    expect($response->json('id'))->toBe($id)
        ->and($response->json('status'))->toBe('paid');

    $order = Order::find($id);
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull();

    $event = PaymentEvent::find($payload['event_id']);
    expect($event->applied_at)->not->toBeNull()
        ->and($event->apply_result->value)->toBe('applied');

    // Проводка оплаты появилась, выдача поставлена в очередь.
    expect(LedgerEntry::where('ref_id', $payload['event_id'])->count())->toBe(2);
    Queue::assertPushed(DeliverOrderJob::class);
});

it('подхватывает осиротевший отказ при создании заказа', function () {
    $payload = orphanEvent(nextOrderId(), ['status' => 'failed']);

    $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'])
        ->assertCreated()
        ->assertJsonPath('status', 'payment_failed');

    expect(PaymentEvent::find($payload['event_id'])->apply_result->value)->toBe('applied');
    // Отказ денег не двигает — проводки нет.
    expect(LedgerEntry::count())->toBe(0);
    Queue::assertNotPushed(DeliverOrderJob::class);
});

it('применяет накопленные события по occurred_at, а не по порядку прихода', function () {
    // Отказ случился РАНЬШЕ оплаты, но пришёл к нам вторым.
    $id = nextOrderId();
    $paid = orphanEvent($id, ['created_at' => '2026-09-02T12:00:05Z']);
    $failed = orphanEvent($id, ['status' => 'failed', 'created_at' => '2026-09-02T12:00:00Z']);

    $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'])->assertCreated();

    // Разобрано в порядке occurred_at: created → payment_failed → paid.
    expect(Order::find($id)->status)->toBe(OrderStatus::Paid)
        ->and(PaymentEvent::find($failed['event_id'])->apply_result->value)->toBe('applied')
        ->and(PaymentEvent::find($paid['event_id'])->apply_result->value)->toBe('applied');

    // Оплата признана ровно одной парой проводок.
    expect(LedgerEntry::where('ref_id', $paid['event_id'])->count())->toBe(2)
        ->and(LedgerEntry::count())->toBe(2);
});

it('помечает stale отказ, случившийся после оплаты', function () {
    $id = nextOrderId();
    $paid = orphanEvent($id, ['created_at' => '2026-09-02T12:00:00Z']);
    $failed = orphanEvent($id, ['status' => 'failed', 'created_at' => '2026-09-02T12:00:05Z']);

    $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'])->assertCreated();

    expect(Order::find($id)->status)->toBe(OrderStatus::Paid)
        ->and(PaymentEvent::find($paid['event_id'])->apply_result->value)->toBe('applied')
        ->and(PaymentEvent::find($failed['event_id'])->apply_result->value)->toBe('stale');
});

it('не задваивает проводку, если то же событие придёт снова после подхвата', function () {
    $id = nextOrderId();
    $payload = orphanEvent($id);
    $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'])->assertCreated();

    $paidAt = Order::find($id)->paid_at;

    $this->postJson('/api/webhooks/payment', $payload)
        ->assertOk()
        ->assertJsonPath('result', 'duplicate');

    expect(Order::find($id)->paid_at->eq($paidAt))->toBeTrue()
        ->and(PaymentEvent::count())->toBe(1)
        ->and(LedgerEntry::count())->toBe(2);
});

it('фоновая задача подхватывает событие по заказу, созданному мимо API', function () {
    // Заказ создаётся руками, последовательность не тратится — литерал безопасен.
    $id = 'ord_manual_1';
    $payload = orphanEvent($id);

    // Заказ появился в обход OrderService — ровно тот случай, ради которого
    // существует второй механизм подхвата.
    Order::create([
        'id' => $id,
        'sku' => 'KEY-CS2-PRIME',
        'amount' => '1290.00',
        'currency' => 'RUB',
        'status' => OrderStatus::Created,
    ]);

    (new ReconcileOrphanEvents)->handle(app(PaymentEventProcessor::class));

    expect(Order::find($id)->status)->toBe(OrderStatus::Paid)
        ->and(PaymentEvent::find($payload['event_id'])->applied_at)->not->toBeNull();
    Queue::assertPushed(DeliverOrderJob::class);
});

it('оставляет вечную сироту в покое и не падает на ней', function () {
    // Заказа ord_99999 нет и не будет: применять событие не к чему.
    $payload = orphanEvent('ord_99999');

    (new ReconcileOrphanEvents)->handle(app(PaymentEventProcessor::class));

    $event = PaymentEvent::find($payload['event_id']);
    expect($event->applied_at)->toBeNull()
        ->and($event->apply_result->value)->toBe('orphan');
});

it('повторный подхват уже применённого события — no-op', function () {
    $id = nextOrderId();
    orphanEvent($id);
    $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'])->assertCreated();

    $order = Order::find($id);

    (new ReconcileOrphanEvents)->handle(app(PaymentEventProcessor::class));

    expect(Order::find($id)->updated_at->eq($order->updated_at))->toBeTrue()
        ->and(LedgerEntry::count())->toBe(2);
});
