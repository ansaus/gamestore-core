<?php

use App\Domain\Stub\StubIssue;
use App\Domain\Stub\StubKey;

beforeEach(fn () => seedShop());

it('выдаёт код и помечает ключ занятым', function () {
    $response = $this->postJson('/supplier/a/issue', [
        'request_id' => 'req_ord_00001_A',
        'order_id' => 'ord_00001',
        'sku' => 'KEY-CS2-PRIME',
    ]);

    $response->assertOk()->assertJsonPath('status', 'ok');

    $code = $response->json('code');
    $key = StubKey::where('code', $code)->firstOrFail();

    expect($key->status)->toBe('issued')
        ->and($key->request_id)->toBe('req_ord_00001_A')
        ->and($key->supplier)->toBe('A');
});

it('на повтор с тем же request_id возвращает тот же код', function () {
    $payload = [
        'request_id' => 'req_ord_00001_A',
        'order_id' => 'ord_00001',
        'sku' => 'KEY-CS2-PRIME',
    ];

    $first = $this->postJson('/supplier/a/issue', $payload)->assertOk();
    $second = $this->postJson('/supplier/a/issue', $payload)->assertOk();

    expect($second->json('code'))->toBe($first->json('code'))
        ->and($second->json('replayed'))->toBeTrue()
        // Вторая выдача не создана — на этом стоит вся ловушка таймаута.
        ->and(StubIssue::count())->toBe(1)
        ->and(StubKey::where('status', 'issued')->count())->toBe(1);
});

it('выдаёт разным request_id разные коды', function () {
    $codes = collect(['req_1', 'req_2', 'req_3'])->map(fn (string $rid) => $this->postJson('/supplier/a/issue', [
        'request_id' => $rid,
        'order_id' => 'ord_00001',
        'sku' => 'KEY-CS2-PRIME',
    ])->json('code'));

    expect($codes->unique())->toHaveCount(3);
});

it('отвечает 409 out_of_stock на пустом пуле', function () {
    StubKey::where('supplier', 'A')->delete();

    $this->postJson('/supplier/a/issue', [
        'request_id' => 'req_ord_00001_A',
        'order_id' => 'ord_00001',
        'sku' => 'KEY-CS2-PRIME',
    ])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'out_of_stock');
});

it('держит пулы поставщиков раздельно', function () {
    StubKey::where('supplier', 'A')->delete();

    $this->postJson('/supplier/b/issue', [
        'request_id' => 'req_ord_00001_B',
        'order_id' => 'ord_00001',
        'sku' => 'KEY-CS2-PRIME',
    ])->assertOk();
});

it('подчиняется force=http_500 и не тратит ключ', function () {
    $this->postJson('/stub/config', ['supplier' => 'A', 'force' => 'http_500'])->assertOk();

    $this->postJson('/supplier/a/issue', [
        'request_id' => 'req_ord_00001_A',
        'order_id' => 'ord_00001',
        'sku' => 'KEY-CS2-PRIME',
    ])->assertStatus(500);

    expect(StubIssue::count())->toBe(0)
        ->and(StubKey::where('status', 'issued')->count())->toBe(0);
});

it('подчиняется force=out_of_stock', function () {
    $this->postJson('/stub/config', ['supplier' => 'A', 'force' => 'out_of_stock'])->assertOk();

    $this->postJson('/supplier/a/issue', [
        'request_id' => 'req_ord_00001_A',
        'order_id' => 'ord_00001',
        'sku' => 'KEY-CS2-PRIME',
    ])->assertStatus(409);
});

it('фиксирует выдачу в БД до того, как зависнуть', function () {
    // timeout_after_issue с нулевым сном: проверяем именно порядок действий,
    // а не то, что заглушка умеет спать.
    $this->postJson('/stub/config', [
        'supplier' => 'A',
        'force' => 'timeout_after_issue',
        'timeout_sleep_ms' => 0,
    ])->assertOk();

    $this->postJson('/supplier/a/issue', [
        'request_id' => 'req_ord_00001_A',
        'order_id' => 'ord_00001',
        'sku' => 'KEY-CS2-PRIME',
    ])->assertOk();

    // Код выдан и записан — значит повтор после таймаута вернёт именно его.
    expect(StubIssue::count())->toBe(1);

    $this->postJson('/stub/config', ['supplier' => 'A', 'force' => 'none'])->assertOk();

    $replay = $this->postJson('/supplier/a/issue', [
        'request_id' => 'req_ord_00001_A',
        'order_id' => 'ord_00001',
        'sku' => 'KEY-CS2-PRIME',
    ])->assertOk();

    expect($replay->json('replayed'))->toBeTrue()
        ->and(StubIssue::count())->toBe(1);
});

it('отдаёт состояние и сбрасывает его', function () {
    $this->postJson('/supplier/a/issue', [
        'request_id' => 'req_ord_00001_A',
        'order_id' => 'ord_00001',
        'sku' => 'KEY-CS2-PRIME',
    ])->assertOk();

    $this->getJson('/stub/state')
        ->assertOk()
        ->assertJsonPath('suppliers.A.issued', 1)
        ->assertJsonPath('suppliers.A.free', 29)
        ->assertJsonPath('suppliers.B.free', 20);

    $this->postJson('/stub/reset')
        ->assertOk()
        ->assertJsonPath('suppliers.A.free', 30)
        ->assertJsonPath('suppliers.A.issued', 0);

    expect(StubIssue::count())->toBe(0);
});

it('не знает поставщиков кроме A и B', function () {
    $this->postJson('/supplier/c/issue', [
        'request_id' => 'req_1',
        'order_id' => 'ord_00001',
        'sku' => 'KEY-CS2-PRIME',
    ])->assertNotFound();
});
