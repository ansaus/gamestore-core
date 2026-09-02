<?php

use App\Domain\Ledger\LedgerEntry;
use App\Domain\Order\Order;
use App\Domain\Order\OrderStatus;
use App\Domain\Payment\PaymentEvent;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    seedShop();
    // Выдача проверяется отдельно; здесь важна только реакция на событие.
    Queue::fake();
});

function newOrder(string $sku = 'KEY-CS2-PRIME'): string
{
    return test()->postJson('/api/orders', ['sku' => $sku])->json('id');
}

it('применяет paid к созданному заказу и пишет проводку оплаты', function () {
    $id = newOrder();
    $payload = webhookPayload($id);

    $this->postJson('/api/webhooks/payment', $payload)
        ->assertOk()
        ->assertJsonPath('result', 'applied');

    $order = Order::find($id);
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull();

    $entries = LedgerEntry::where('ref_id', $payload['event_id'])->get();
    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('direction')->sort()->values()->all())->toBe(['credit', 'debit']);
});

it('распознаёт повтор того же event_id и ничего не меняет', function () {
    $id = newOrder();
    $payload = webhookPayload($id);

    $this->postJson('/api/webhooks/payment', $payload)->assertOk();
    $paidAt = Order::find($id)->paid_at;

    $this->postJson('/api/webhooks/payment', $payload)
        ->assertOk()
        ->assertJsonPath('result', 'duplicate');

    expect(PaymentEvent::count())->toBe(1)
        ->and(Order::find($id)->paid_at->eq($paidAt))->toBeTrue()
        // Проводка не задвоилась: UNIQUE по (ref_type, ref_id, account, direction).
        ->and(LedgerEntry::count())->toBe(2);
});

it('сохраняет событие по несуществующему заказу как сироту', function () {
    $payload = webhookPayload('ord_99999');

    $this->postJson('/api/webhooks/payment', $payload)
        ->assertOk()
        ->assertJsonPath('result', 'orphan');

    $event = PaymentEvent::find($payload['event_id']);

    expect($event->apply_result->value)->toBe('orphan')
        // applied_at остаётся null — по нему сироту найдёт подхват на этапе 2.
        ->and($event->applied_at)->toBeNull();
});

it('переводит созданный заказ в payment_failed', function () {
    $id = newOrder();

    $this->postJson('/api/webhooks/payment', webhookPayload($id, ['status' => 'failed']))
        ->assertOk()
        ->assertJsonPath('result', 'applied');

    expect(Order::find($id)->status)->toBe(OrderStatus::PaymentFailed);
});

it('не откатывает оплаченный заказ поздним failed', function () {
    $id = newOrder();
    $this->postJson('/api/webhooks/payment', webhookPayload($id))->assertOk();

    $this->postJson('/api/webhooks/payment', webhookPayload($id, ['status' => 'failed']))
        ->assertOk()
        ->assertJsonPath('result', 'stale');

    expect(Order::find($id)->status)->toBe(OrderStatus::Paid);
});

it('пропускает поздний paid поверх payment_failed', function () {
    $id = newOrder();
    $this->postJson('/api/webhooks/payment', webhookPayload($id, ['status' => 'failed']))->assertOk();

    $this->postJson('/api/webhooks/payment', webhookPayload($id))
        ->assertOk()
        ->assertJsonPath('result', 'applied');

    expect(Order::find($id)->status)->toBe(OrderStatus::Paid);
});

it('игнорирует повторный paid по другому event_id', function () {
    $id = newOrder();
    $this->postJson('/api/webhooks/payment', webhookPayload($id))->assertOk();

    $this->postJson('/api/webhooks/payment', webhookPayload($id))
        ->assertOk()
        ->assertJsonPath('result', 'ignored_terminal');

    expect(Order::find($id)->status)->toBe(OrderStatus::Paid)
        // Вторая задача выдачи не ставится.
        ->and(LedgerEntry::where('ref_type', 'payment_event')->count())->toBe(2);
});

it('применяет событие с расхождением суммы и поднимает флаг', function () {
    $id = newOrder();
    $payload = webhookPayload($id, ['amount' => 999]);

    $this->postJson('/api/webhooks/payment', $payload)
        ->assertOk()
        ->assertJsonPath('result', 'applied');

    $event = PaymentEvent::find($payload['event_id']);

    expect($event->amount_mismatch)->toBeTrue()
        // Деньги пришли — игнорировать нельзя, статус меняется.
        ->and(Order::find($id)->status)->toBe(OrderStatus::Paid);
});

it('не поднимает флаг при совпадении суммы', function () {
    $id = newOrder();
    $payload = webhookPayload($id);

    $this->postJson('/api/webhooks/payment', $payload)->assertOk();

    expect(PaymentEvent::find($payload['event_id'])->amount_mismatch)->toBeFalse();
});

it('отвечает 400 на невалидный payload', function () {
    $this->postJson('/api/webhooks/payment', ['order_id' => 'ord_00001'])
        ->assertStatus(400)
        ->assertJsonPath('result', 'invalid_payload');

    $this->postJson('/api/webhooks/payment', webhookPayload('ord_00001', ['status' => 'refunded']))
        ->assertStatus(400);
});
