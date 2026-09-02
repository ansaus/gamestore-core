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

/** Создаёт заказ и доводит его до оплаты. */
function paidOrder(string $sku = 'KEY-CS2-PRIME'): Order
{
    $id = test()->postJson('/api/orders', ['sku' => $sku])->json('id');
    test()->postJson('/api/webhooks/payment', webhookPayload($id))->assertOk();

    return Order::findOrFail($id);
}

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

it('уводит заказ в out_of_stock, когда пул пуст', function () {
    useInProcessSupplier();
    StubKey::where('supplier', 'A')->delete();

    $order = paidOrder();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::OutOfStock)
        ->and(Delivery::count())->toBe(0);

    $request = SupplierRequest::findOrFail("req_{$order->id}_A");
    expect($request->state)->toBe(SupplierRequestState::Failed)
        ->and($request->error_reason)->toBe('out_of_stock');
});

it('трактует 5xx поставщика как неопределённость, а не отказ', function () {
    Http::fake(fn () => Http::response(['status' => 'error', 'reason' => 'internal'], 500));

    $order = paidOrder();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::DeliveryFailed);

    // Состояние unknown: поставщик МОГ выдать код. Уходить к другому нельзя.
    expect(SupplierRequest::findOrFail("req_{$order->id}_A")->state)
        ->toBe(SupplierRequestState::Unknown);
});

it('трактует таймаут как неопределённость', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $order = paidOrder();

    expect($order->refresh()->status)->toBe(OrderStatus::DeliveryFailed)
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
