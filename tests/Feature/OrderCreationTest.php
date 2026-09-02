<?php

use App\Domain\Catalog\Product;
use App\Domain\Order\Order;

beforeEach(fn () => seedShop());

it('создаёт заказ и фиксирует цену из каталога', function () {
    $response = $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME']);

    $response->assertCreated()
        ->assertJsonPath('sku', 'KEY-CS2-PRIME')
        ->assertJsonPath('amount', '1290.00')
        ->assertJsonPath('currency', 'RUB')
        ->assertJsonPath('status', 'created')
        ->assertJsonPath('delivery', null);

    expect($response->json('id'))->toStartWith('ord_');
});

it('отдаёт 404 на неизвестный sku', function () {
    $this->postJson('/api/orders', ['sku' => 'NO-SUCH-SKU'])
        ->assertNotFound()
        ->assertJsonPath('error', 'sku_not_found');
});

it('отдаёт 409 на неактивный sku', function () {
    Product::where('sku', 'KEY-GTA5')->update(['is_active' => false]);

    $this->postJson('/api/orders', ['sku' => 'KEY-GTA5'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'sku_inactive');
});

it('возвращает тот же заказ на повтор с тем же Idempotency-Key', function () {
    $headers = ['Idempotency-Key' => 'key-42'];

    $first = $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'], $headers)->assertCreated();
    $second = $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'], $headers)->assertOk();

    expect($second->json('id'))->toBe($first->json('id'));
    expect(Order::count())->toBe(1);
});

it('без Idempotency-Key создаёт разные заказы', function () {
    $first = $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'])->assertCreated();
    $second = $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'])->assertCreated();

    expect($second->json('id'))->not->toBe($first->json('id'));
    expect(Order::count())->toBe(2);
});

it('отдаёт заказ по id и 404 на несуществующий', function () {
    $id = $this->postJson('/api/orders', ['sku' => 'SUB-DISCORD-1M'])->json('id');

    $this->getJson("/api/orders/{$id}")
        ->assertOk()
        ->assertJsonPath('id', $id)
        ->assertJsonPath('timeline.0.status', 'created');

    $this->getJson('/api/orders/ord_99999')->assertNotFound();
});
