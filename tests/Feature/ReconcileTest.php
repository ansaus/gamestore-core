<?php

use App\Domain\Delivery\Delivery;
use App\Domain\Delivery\UnclaimedCode;
use App\Domain\Ledger\LedgerEntry;
use App\Domain\Order\OrderStatus;
use App\Domain\Reconcile\ReconcileReport;
use App\Jobs\DeliverOrderJob;
use App\Jobs\ReconcileStuckOrders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
| Этап 4 (SPEC §8): отчёт сверки, ручные ручки, фоновое доведение.
*/

beforeEach(fn () => seedShop());

function reconcileReport(): array
{
    return app(ReconcileReport::class)->build();
}

/** Состаривает заказ так, чтобы он вышел из окна grace. */
function ageOrder(string $orderId, int $seconds = 120): void
{
    $at = now()->subSeconds($seconds);

    DB::table('orders')->where('id', $orderId)->update([
        'paid_at' => $at,
        'updated_at' => $at,
    ]);
}

// ------------------------------------------------------------------- отчёт

it('на здоровой системе отдаёт healthy: true', function () {
    useInProcessSupplier();
    paidOrder();

    $result = reconcileReport();

    expect($result['healthy'])->toBeTrue()
        ->and($result['ledger']['balanced'])->toBeTrue()
        ->and($result['paid_not_delivered'])->toBeEmpty();
});

it('не считает находкой заказ, оплаченный только что', function () {
    Queue::fake();
    paidOrder();

    // Асинхронность — не расхождение: выдача ещё просто не успела.
    expect(reconcileReport()['paid_not_delivered'])->toBeEmpty()
        ->and(reconcileReport()['healthy'])->toBeTrue();
});

it('показывает оплаченный, но не выданный заказ старше grace', function () {
    Queue::fake();
    $order = paidOrder();
    ageOrder($order->id);

    $result = reconcileReport();

    expect($result['paid_not_delivered'])->toHaveCount(1)
        ->and($result['paid_not_delivered'][0]['order_id'])->toBe($order->id)
        ->and($result['paid_not_delivered'][0]['status'])->toBe('paid')
        ->and($result['paid_not_delivered'][0]['age_sec'])->toBeGreaterThanOrEqual(60)
        ->and($result['healthy'])->toBeFalse();
});

it('показывает заказ, застрявший в delivering', function () {
    Http::fake(fn () => Http::response(['status' => 'error', 'reason' => 'internal'], 500));

    $order = paidOrder();
    ageOrder($order->id);

    $result = reconcileReport();

    expect($order->refresh()->status)->toBe(OrderStatus::Delivering)
        ->and($result['stuck_in_delivering'])->toHaveCount(1)
        ->and($result['stuck_in_delivering'][0]['order_id'])->toBe($order->id);
});

it('показывает невыясненные заявки к поставщикам', function () {
    // Без этой секции зависшая заявка невидима: заказ может быть уже закрыт,
    // а мы так и не узнаем, списал ли поставщик ключ.
    Http::fake(fn () => Http::response(['status' => 'error', 'reason' => 'internal'], 500));

    $order = paidOrder();
    $result = reconcileReport();

    expect($result['unknown_supplier_requests'])->toHaveCount(1);

    $finding = $result['unknown_supplier_requests'][0];
    expect($finding['request_id'])->toBe("req_{$order->id}_A")
        ->and($finding['supplier'])->toBe('A')
        ->and($finding['attempts'])->toBe(config('gamestore.supplier.max_attempts'))
        ->and($finding['error_reason'])->toBe('internal')
        ->and($result['healthy'])->toBeFalse();
});

it('показывает неприкаянные коды', function () {
    useInProcessSupplier();
    $order = paidOrder();

    // Код, пришедший после закрытия заказа: деньги за него уплачены,
    // забывать о нём нельзя.
    UnclaimedCode::create([
        'request_id' => "req_{$order->id}_B",
        'order_id' => $order->id,
        'supplier' => 'B',
        'code' => 'LATE-CODE',
        'reason' => 'late_response_after_delivery',
    ]);

    $result = reconcileReport();

    expect($result['unclaimed_codes'])->toHaveCount(1)
        ->and($result['unclaimed_codes'][0]['code'])->toBe('LATE-CODE')
        ->and($result['unclaimed_codes'][0]['reason'])->toBe('late_response_after_delivery')
        ->and($result['healthy'])->toBeFalse();
});

it('показывает осиротевшие события старше grace', function () {
    Queue::fake();
    $payload = webhookPayload('ord_99999');
    $this->postJson('/api/webhooks/payment', $payload)->assertOk();

    DB::table('payment_events')->update(['received_at' => now()->subMinutes(5)]);

    $result = reconcileReport();

    expect($result['orphan_events'])->toHaveCount(1)
        ->and($result['orphan_events'][0]['event_id'])->toBe($payload['event_id'])
        ->and($result['orphan_events'][0]['order_exists'])->toBeFalse();
});

it('показывает расхождения по сумме', function () {
    Queue::fake();
    $id = $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'])->json('id');
    $this->postJson('/api/webhooks/payment', webhookPayload($id, ['amount' => 1000]))->assertOk();

    $result = reconcileReport();

    expect($result['amount_mismatch_events'])->toHaveCount(1)
        ->and($result['amount_mismatch_events'][0]['order_id'])->toBe($id)
        ->and($result['healthy'])->toBeFalse();
});

// ------------------------------------------------------------- I6, баланс

it('ловит перекос журнала — это инвариант I6, а не косметика', function () {
    useInProcessSupplier();
    $order = paidOrder();

    expect(reconcileReport()['ledger']['balanced'])->toBeTrue();

    // Одна непарная проводка — и журнал больше не сходится.
    LedgerEntry::create([
        'order_id' => $order->id,
        'account' => 'customer',
        'direction' => 'debit',
        'amount' => '1.00',
        'ref_type' => 'payment_event',
        'ref_id' => 'evt_broken',
    ]);

    $result = reconcileReport();

    expect($result['ledger']['balanced'])->toBeFalse()
        ->and($result['healthy'])->toBeFalse()
        // И видно, на каком именно заказе перекос.
        ->and($result['unbalanced_orders'])->toHaveCount(1)
        ->and($result['unbalanced_orders'][0]['order_id'])->toBe($order->id);
});

it('ловит перекос по заказу даже когда глобально сходится', function () {
    useInProcessSupplier();
    $first = paidOrder();
    $second = paidOrder();

    // Две ошибки, компенсирующие друг друга: глобальная сумма сойдётся,
    // а по заказам — нет. Ровно поэтому I6 проверяется в двух разрезах.
    LedgerEntry::create([
        'order_id' => $first->id, 'account' => 'customer', 'direction' => 'debit',
        'amount' => '5.00', 'ref_type' => 'payment_event', 'ref_id' => 'evt_skew_1',
    ]);
    LedgerEntry::create([
        'order_id' => $second->id, 'account' => 'customer', 'direction' => 'credit',
        'amount' => '5.00', 'ref_type' => 'payment_event', 'ref_id' => 'evt_skew_2',
    ]);

    $result = reconcileReport();

    expect($result['ledger']['balanced'])->toBeTrue()
        ->and($result['unbalanced_orders'])->toHaveCount(2)
        ->and($result['healthy'])->toBeFalse();
});

// --------------------------------------------------------- фоновое доведение

it('возвращается к заказу, застрявшему без выдачи', function () {
    Queue::fake();
    $order = paidOrder();
    ageOrder($order->id);

    // Сбрасываем фейк: джоба от оплаты уже отработала своё, считаем только
    // то, что поставит watchdog.
    Queue::fake();
    (new ReconcileStuckOrders)->handle();

    Queue::assertPushed(DeliverOrderJob::class, fn ($job) => $job->orderId === $order->id);

    // Лизинг: следующий тик этот заказ уже не заберёт.
    expect($order->refresh()->next_attempt_at->isFuture())->toBeTrue();
});

it('не трогает выданный заказ', function () {
    useInProcessSupplier();
    $order = paidOrder();
    ageOrder($order->id);

    Queue::fake();
    (new ReconcileStuckOrders)->handle();

    Queue::assertNothingPushed();
});

it('не трогает заказ, чей бэкофф ещё не истёк', function () {
    Http::fake(fn () => Http::response(['status' => 'error', 'reason' => 'internal'], 500));
    $order = paidOrder();
    ageOrder($order->id);

    // next_attempt_at проставлен выдачей и ещё в будущем.
    expect($order->refresh()->next_attempt_at->isFuture())->toBeTrue();

    Queue::fake();
    (new ReconcileStuckOrders)->handle();

    Queue::assertNothingPushed();
});

it('оставляет человеку заказ, исчерпавший потолок циклов', function () {
    Queue::fake();
    $order = paidOrder();
    ageOrder($order->id);

    DB::table('orders')->where('id', $order->id)->update([
        'attempts' => config('gamestore.reconcile.max_attempts'),
        'next_attempt_at' => null,
    ]);

    Queue::fake();
    (new ReconcileStuckOrders)->handle();

    // Бесконечно долбиться нельзя — дальше только retry-delivery руками.
    Queue::assertNothingPushed();
    expect(reconcileReport()['paid_not_delivered'])->toHaveCount(1);
});

it('разводит бэкофф всё дальше с каждым циклом', function () {
    Queue::fake();
    $order = paidOrder();
    ageOrder($order->id);

    $delays = [];

    foreach ([1, 3, 5] as $attempts) {
        DB::table('orders')->where('id', $order->id)
            ->update(['attempts' => $attempts, 'next_attempt_at' => null]);

        (new ReconcileStuckOrders)->handle();

        $delays[] = (int) round(now()->diffInSeconds($order->refresh()->next_attempt_at, absolute: true));
    }

    expect($delays[1])->toBeGreaterThan($delays[0])
        ->and($delays[2])->toBeGreaterThan($delays[1])
        ->and($delays[2])->toBeLessThanOrEqual(config('gamestore.reconcile.max_backoff_seconds'));
});

// ------------------------------------------------------------ ручные ручки

it('отдаёт отчёт по HTTP', function () {
    useInProcessSupplier();
    paidOrder();

    $this->getJson('/api/admin/reconcile')
        ->assertOk()
        ->assertJsonPath('healthy', true)
        ->assertJsonPath('ledger.balanced', true)
        ->assertJsonStructure([
            'paid_not_delivered', 'delivered_not_paid', 'orphan_events',
            'stuck_in_delivering', 'unknown_supplier_requests', 'unclaimed_codes',
            'amount_mismatch_events', 'unbalanced_orders',
            'ledger' => ['debit', 'credit', 'entries', 'balanced'],
            'healthy',
        ]);
});

it('ручной retry-delivery ставит выдачу в очередь и обнуляет счётчик', function () {
    Http::fake(fn () => Http::response(['status' => 'error', 'reason' => 'internal'], 500));
    $order = paidOrder();

    Queue::fake();

    $this->postJson("/api/admin/orders/{$order->id}/retry-delivery")
        ->assertOk()
        ->assertJsonPath('result', 'delivery_dispatched');

    Queue::assertPushed(DeliverOrderJob::class, fn ($job) => $job->orderId === $order->id);

    // Человек вмешался — даём заказу свежий бюджет попыток.
    expect($order->refresh()->attempts)->toBe(0)
        ->and($order->next_attempt_at)->toBeNull();
});

it('не даёт повторно выдать уже выданный заказ', function () {
    useInProcessSupplier();
    $order = paidOrder();

    $this->postJson("/api/admin/orders/{$order->id}/retry-delivery")
        ->assertStatus(409)
        ->assertJsonPath('error', 'already_delivered');

    expect(Delivery::count())->toBe(1);
});

it('не даёт доставить неоплаченный заказ', function () {
    $id = $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'])->json('id');

    $this->postJson("/api/admin/orders/{$id}/retry-delivery")
        ->assertStatus(409)
        ->assertJsonPath('error', 'not_payable_for_delivery');
});

it('отдаёт 404 на retry-delivery несуществующего заказа', function () {
    $this->postJson('/api/admin/orders/ord_99999/retry-delivery')->assertStatus(404);
});

it('пополнение поднимает остаток и будит ждущие заказы', function () {
    Http::fake(fn () => Http::response(['status' => 'error', 'reason' => 'out_of_stock'], 409));

    $order = paidOrder();
    expect($order->refresh()->status)->toBe(OrderStatus::OutOfStock);

    $before = (int) DB::table('product_stock')->where('sku', 'KEY-CS2-PRIME')->value('available');

    $this->postJson('/api/admin/stock/KEY-CS2-PRIME/refill', ['quantity' => 25])
        ->assertOk()
        ->assertJsonPath('available', $before + 25)
        ->assertJsonPath('woken_orders', 1);

    // Заказ снова готов к доведению прямо сейчас, без ожидания бэкоффа.
    expect($order->refresh()->next_attempt_at->isFuture())->toBeFalse()
        ->and($order->attempts)->toBe(0);
});

it('валидирует пополнение', function () {
    $this->postJson('/api/admin/stock/KEY-CS2-PRIME/refill', ['quantity' => 0])->assertStatus(422);
    $this->postJson('/api/admin/stock/NO-SUCH-SKU/refill', ['quantity' => 1])->assertStatus(404);
});

it('консольный отчёт возвращает ненулевой код при находках', function () {
    Queue::fake();
    $order = paidOrder();
    ageOrder($order->id);

    $this->artisan('reconcile:report')->assertExitCode(1);
});
