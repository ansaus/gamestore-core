<?php

use App\Domain\Delivery\Delivery;
use App\Domain\Delivery\DeliveryService;
use App\Domain\Delivery\SupplierRequest;
use App\Domain\Delivery\SupplierRequestState;
use App\Domain\Delivery\UnclaimedCode;
use App\Domain\Order\Order;
use App\Domain\Order\OrderStatus;
use App\Domain\Stub\StubIssue;
use App\Domain\Stub\StubKey;
use App\Jobs\DeliverOrderJob;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => seedShop());

it('доводит оплаченный заказ до delivered с кодом из пула', function () {
    useInProcessSupplier();

    $order = paidOrder();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->delivered_at)->not->toBeNull();

    $delivery = Delivery::findOrFail($order->id);
    expect($delivery->supplier)->toBe('A')
        ->and($delivery->request_id)->toBe("req_{$order->id}_A")
        // Код действительно ушёл из пула заглушки.
        ->and(StubKey::where('code', $delivery->code)->first()->status)->toBe('issued');

    expect(SupplierRequest::find($delivery->request_id)->state)
        ->toBe(SupplierRequestState::Succeeded);
});

it('списывает остаток витрины ровно один раз', function () {
    useInProcessSupplier();

    $before = DB::table('product_stock')->where('sku', 'KEY-CS2-PRIME')->value('available');
    paidOrder();
    $after = DB::table('product_stock')->where('sku', 'KEY-CS2-PRIME')->value('available');

    expect($after)->toBe($before - 1);
});

it('повторный запуск выдачи для выданного заказа ничего не меняет', function () {
    useInProcessSupplier();

    $order = paidOrder();
    $code = Delivery::findOrFail($order->id)->code;

    // Так фоновое доведение (этап 4) сможет безопасно перезапускать выдачу.
    (new DeliverOrderJob($order->id))->handle(app(DeliveryService::class));

    expect(Delivery::count())->toBe(1)
        ->and(Delivery::findOrFail($order->id)->code)->toBe($code)
        ->and(StubIssue::count())->toBe(1);
});

it('уводит заказ в out_of_stock, когда пусты пулы обоих поставщиков', function () {
    useInProcessSupplier();
    StubKey::query()->delete();

    $order = paidOrder();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::OutOfStock)
        ->and(Delivery::count())->toBe(0)
        // Состояние восстановимое: после пополнения фоновое доведение вернётся.
        ->and($order->next_attempt_at)->not->toBeNull();

    // Оба поставщика отказали ОПРЕДЕЛЁННО — ключ не потрачен ни у кого.
    foreach (['A', 'B'] as $supplier) {
        $request = SupplierRequest::findOrFail("req_{$order->id}_{$supplier}");
        expect($request->state)->toBe(SupplierRequestState::Failed)
            ->and($request->error_reason)->toBe('out_of_stock');
    }
});

it('трактует 5xx поставщика как неопределённость, а не отказ', function () {
    Http::fake(fn () => Http::response(['status' => 'error', 'reason' => 'internal'], 500));

    $order = paidOrder();

    // Состояние unknown: поставщик МОГ выдать код. Уходить к другому нельзя,
    // проваливать заказ тоже не за что — исход просто не выяснен.
    expect($order->refresh()->status)->toBe(OrderStatus::Delivering)
        ->and(SupplierRequest::findOrFail("req_{$order->id}_A")->state)
        ->toBe(SupplierRequestState::Unknown);
});

it('трактует таймаут как неопределённость', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $order = paidOrder();

    expect($order->refresh()->status)->toBe(OrderStatus::Delivering)
        ->and(SupplierRequest::findOrFail("req_{$order->id}_A")->state)
        ->toBe(SupplierRequestState::Unknown);
});

it('не создаёт вторую выдачу, если код пришёл после закрытия заказа', function () {
    $order = null;

    // Пока мы ходили к поставщику, заказ выдал кто-то ещё.
    Http::fake(function () use (&$order) {
        Delivery::create([
            'order_id' => $order->id,
            'code' => 'WINNER-CODE',
            'supplier' => 'A',
            'request_id' => "req_{$order->id}_A",
        ]);

        DB::table('orders')->where('id', $order->id)->update([
            'status' => OrderStatus::Delivered->value,
            'delivered_at' => now(),
        ]);

        return Http::response(['status' => 'ok', 'code' => 'LATE-CODE'], 200);
    });

    $id = $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'])->json('id');
    $order = Order::findOrFail($id);
    $this->postJson('/api/webhooks/payment', webhookPayload($id))->assertOk();

    expect(Delivery::count())->toBe(1)
        ->and(Delivery::findOrFail($id)->code)->toBe('WINNER-CODE');

    // Опоздавший код не выброшен: он оплачен и идёт в сверку.
    $unclaimed = UnclaimedCode::firstOrFail();
    expect($unclaimed->code)->toBe('LATE-CODE')
        ->and($unclaimed->reason)->toBe('late_response_after_delivery');
});

it('не запускает выдачу для неоплаченного заказа', function () {
    useInProcessSupplier();

    $id = $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'])->json('id');

    (new DeliverOrderJob($id))->handle(app(DeliveryService::class));

    expect(Order::findOrFail($id)->status)->toBe(OrderStatus::Created)
        ->and(Delivery::count())->toBe(0);
});
