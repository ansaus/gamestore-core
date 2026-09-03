<?php

use App\Domain\Delivery\Delivery;
use App\Domain\Delivery\DeliveryService;
use App\Domain\Delivery\SupplierRequest;
use App\Domain\Delivery\SupplierRequestState;
use App\Domain\Delivery\UnclaimedCode;
use App\Domain\Order\Order;
use App\Domain\Order\OrderStatus;
use App\Domain\Stub\StubConfig;
use App\Domain\Stub\StubIssue;
use App\Domain\Stub\StubKey;
use App\Jobs\DeliverOrderJob;
use Illuminate\Support\Facades\Http;

/*
| Этап 3 (SPEC §7): ретраи тем же request_id, «таймаут ≠ отказ»,
| фолбэк A→B только при ОПРЕДЕЛЁННОМ отказе A.
*/

beforeEach(fn () => seedShop());

function deliverAgain(Order $order): Order
{
    (new DeliverOrderJob($order->id))->handle(app(DeliveryService::class));

    return $order->refresh();
}

/** Оплаченный заказ, выданный поставщиком с заданным ответом. */
function paidOrderWith(callable $response): Order
{
    Http::fake($response);

    return paidOrder();
}

// ------------------------------------------------- сценарий 4: ловушка таймаута

it('после таймаута забирает тот же код повтором и не тратит второй ключ', function () {
    // Заглушка фиксирует выдачу в БД и «теряет» ответ. Спать реальные
    // секунды в тесте незачем — важно, что ответ до клиента не дошёл.
    StubConfig::set('A', ['force' => 'timeout_after_issue', 'timeout_sleep_ms' => 0]);
    useInProcessSupplier();

    $order = paidOrder()->refresh();

    expect($order->status)->toBe(OrderStatus::Delivered);

    // Главное: поставщик выдал ровно один ключ, хотя обращений было несколько.
    $issue = StubIssue::sole();
    $delivery = Delivery::findOrFail($order->id);

    expect(StubIssue::count())->toBe(1)
        ->and($delivery->code)->toBe($issue->code)
        ->and($issue->request_id)->toBe("req_{$order->id}_A")
        // request_id не менялся между попытками — иначе выдач было бы две.
        ->and($delivery->request_id)->toBe($issue->request_id)
        ->and($delivery->supplier)->toBe('A')
        ->and(StubKey::where('status', 'issued')->count())->toBe(1);

    $request = SupplierRequest::findOrFail("req_{$order->id}_A");
    expect($request->state)->toBe(SupplierRequestState::Succeeded)
        // Первая попытка потерялась, вторая забрала код: две попытки, один ключ.
        ->and($request->attempts)->toBe(2);
});

// -------------------------------------------------------- ретраи и их границы

it('ретраит 5xx тем же request_id заданное число раз', function () {
    $seen = [];
    Http::fake(function ($request) use (&$seen) {
        $seen[] = $request->data()['request_id'];

        return Http::response(['status' => 'error', 'reason' => 'internal'], 500);
    });

    $order = paidOrder();

    expect($seen)->toHaveCount(config('gamestore.supplier.max_attempts'))
        // Один и тот же request_id на всех попытках — это и есть probe.
        ->and(array_unique($seen))->toBe(["req_{$order->id}_A"]);

    expect(SupplierRequest::findOrFail("req_{$order->id}_A")->attempts)
        ->toBe(config('gamestore.supplier.max_attempts'));
});

it('не ретраит определённый отказ', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        return Http::response(['status' => 'error', 'reason' => 'out_of_stock'], 409);
    });

    paidOrder();

    // По одной попытке на поставщика: отказ подтверждён, повторять нечего.
    expect($calls)->toBe(2);
});

// ------------------------------------- главное правило: unknown не пускает к B

it('не уходит к B, пока состояние A не выяснено', function () {
    $calls = [];
    Http::fake(function ($request) use (&$calls) {
        $calls[] = $request->url();

        return Http::response(['status' => 'error', 'reason' => 'internal'], 500);
    });

    $order = paidOrder()->refresh();

    // К B не ходили ни разу: A мог выдать код, и второй ключ мы бы потеряли.
    expect(array_filter($calls, fn ($url) => str_contains($url, '/supplier/b/')))->toBeEmpty()
        ->and(SupplierRequest::where('supplier', 'B')->count())->toBe(0);

    // Заказ не провален — он ждёт следующего цикла.
    expect($order->status)->toBe(OrderStatus::Delivering)
        ->and($order->next_attempt_at)->not->toBeNull()
        ->and($order->next_attempt_at->isFuture())->toBeTrue();
});

it('сдаётся в delivery_failed только после потолка циклов', function () {
    Http::fake(fn () => Http::response(['status' => 'error', 'reason' => 'internal'], 500));

    $max = (int) config('gamestore.supplier.max_delivery_cycles');
    $order = paidOrder()->refresh();

    // Цикл 1 уже прошёл на оплате; крутим до предпоследнего.
    for ($cycle = 2; $cycle < $max; $cycle++) {
        expect(deliverAgain($order)->status)->toBe(OrderStatus::Delivering);
    }

    expect(deliverAgain($order)->status)->toBe(OrderStatus::DeliveryFailed)
        ->and($order->attempts)->toBe($max);
});

it('доводит заказ до выдачи, если ретрай прояснил исход', function () {
    $attempt = 0;
    // A лежит, потом поднимается — тем же request_id забираем код.
    Http::fake(function ($request) use (&$attempt) {
        if (++$attempt === 1) {
            return Http::response(['status' => 'error', 'reason' => 'internal'], 500);
        }

        return Http::response([
            'status' => 'ok',
            'request_id' => $request->data()['request_id'],
            'code' => 'LATE-BUT-FINE',
        ]);
    });

    $order = paidOrder()->refresh();

    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and(Delivery::findOrFail($order->id)->code)->toBe('LATE-BUT-FINE')
        ->and(Delivery::findOrFail($order->id)->supplier)->toBe('A');
});

// ------------------------------------------------------ сценарий 5: фолбэк A→B

it('уходит к B, когда A ответил определённым out_of_stock', function () {
    StubConfig::set('A', ['force' => 'out_of_stock']);
    useInProcessSupplier();

    $order = paidOrder()->refresh();

    expect($order->status)->toBe(OrderStatus::Delivered);

    $delivery = Delivery::findOrFail($order->id);
    expect($delivery->supplier)->toBe('B')
        // У B свой request_id и своя строка в supplier_requests.
        ->and($delivery->request_id)->toBe("req_{$order->id}_B")
        ->and(Delivery::count())->toBe(1);

    expect(SupplierRequest::findOrFail("req_{$order->id}_A")->state)
        ->toBe(SupplierRequestState::Failed)
        ->and(SupplierRequest::findOrFail("req_{$order->id}_B")->state)
        ->toBe(SupplierRequestState::Succeeded);

    // A ключа не тратил — отказ был до выдачи.
    expect(StubIssue::count())->toBe(1)
        ->and(StubIssue::sole()->supplier)->toBe('B')
        ->and(StubKey::where('supplier', 'A')->where('status', 'issued')->count())->toBe(0);
});

it('уходит к B на любом 4xx, а не только на out_of_stock', function () {
    Http::fake([
        '*/supplier/a/*' => Http::response(['status' => 'error', 'reason' => 'bad_request'], 400),
        '*/supplier/b/*' => Http::response(['status' => 'ok', 'code' => 'FROM-B'], 200),
    ]);

    $order = paidOrder()->refresh();

    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and(Delivery::findOrFail($order->id)->supplier)->toBe('B')
        ->and(SupplierRequest::findOrFail("req_{$order->id}_A")->error_reason)->toBe('bad_request');
});

it('не идёт к третьему кругу, если оба поставщика отказали', function () {
    Http::fake(fn () => Http::response(['status' => 'error', 'reason' => 'out_of_stock'], 409));

    $order = paidOrder()->refresh();

    expect($order->status)->toBe(OrderStatus::OutOfStock)
        ->and(SupplierRequest::where('order_id', $order->id)->count())->toBe(2)
        ->and(Delivery::count())->toBe(0);
});

it('не продаёт код, уже ушедший другому заказу', function () {
    // Поставщик выдал код, который в deliveries уже есть. UNIQUE по code (I2)
    // такую продажу не пропустит — важно, что мы её обрабатываем, а не падаем.
    $other = paidOrderWith(fn () => Http::response(
        ['status' => 'ok', 'code' => 'SOLD-ALREADY'], 200
    ));

    expect(Delivery::findOrFail($other->id)->code)->toBe('SOLD-ALREADY');

    $order = paidOrderWith(fn () => Http::response(
        ['status' => 'ok', 'code' => 'SOLD-ALREADY'], 200
    ))->refresh();

    expect(Delivery::count())->toBe(1)
        ->and(Delivery::where('order_id', $order->id)->exists())->toBeFalse()
        // Заказ не провален и не выдан: доведение — за фоновой задачей.
        ->and($order->status)->toBe(OrderStatus::Delivering)
        ->and($order->next_attempt_at)->not->toBeNull();

    $unclaimed = UnclaimedCode::where('order_id', $order->id)->sole();
    expect($unclaimed->code)->toBe('SOLD-ALREADY')
        ->and($unclaimed->reason)->toBe('code_already_sold');
});
