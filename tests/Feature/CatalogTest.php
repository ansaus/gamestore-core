<?php

use App\Domain\Catalog\CatalogCache;
use App\Domain\Catalog\Product;
use App\Domain\Order\Order;
use Illuminate\Support\Facades\DB;

/*
| Этап 5 (SPEC §9): витрина на денормализованном счётчике,
| keyset-пагинация, кеш с инвалидацией по выдаче.
*/

beforeEach(fn () => seedShop());

it('отдаёт витрину с остатком из счётчика', function () {
    $response = $this->getJson('/api/catalog')->assertOk();

    $first = $response->json('items.0');

    expect($first)->toHaveKeys(['sku', 'name', 'type', 'price', 'currency', 'image', 'available'])
        // Деньги строкой, чтобы клиент не превратил их во float.
        ->and($first['price'])->toBeString()
        ->and($first['available'])->toBe(
            (int) DB::table('product_stock')->where('sku', $first['sku'])->value('available')
        );
});

it('не показывает неактивные товары', function () {
    $sku = Product::query()->orderBy('sku')->value('sku');
    Product::where('sku', $sku)->update(['is_active' => false]);

    $skus = collect($this->getJson('/api/catalog')->json('items'))->pluck('sku');

    expect($skus)->not->toContain($sku);
});

it('листает keyset-курсором без пропусков и повторов', function () {
    $all = Product::where('is_active', true)->orderBy('sku')->pluck('sku')->all();

    $seen = [];
    $cursor = null;

    do {
        $page = $this->getJson('/api/catalog?limit=3'.($cursor === null ? '' : "&after={$cursor}"))
            ->assertOk()
            ->json();

        $seen = array_merge($seen, array_column($page['items'], 'sku'));
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);

    expect($seen)->toBe($all)
        ->and($seen)->toHaveCount(count(array_unique($seen)));
});

it('закрывает курсор на последней странице', function () {
    $total = Product::where('is_active', true)->count();

    $page = $this->getJson("/api/catalog?limit={$total}")->assertOk()->json();

    // Ровно все строки уместились — следующей страницы нет.
    expect($page['items'])->toHaveCount($total)
        ->and($page['next_cursor'])->toBeNull();
});

it('фильтрует по типу', function () {
    $items = $this->getJson('/api/catalog?type=key')->assertOk()->json('items');

    expect($items)->not->toBeEmpty()
        ->and(array_unique(array_column($items, 'type')))->toBe(['key']);
});

it('валидирует параметры витрины', function () {
    $this->getJson('/api/catalog?type=nope')->assertStatus(422);
    $this->getJson('/api/catalog?limit=0')->assertStatus(422);
    $this->getJson('/api/catalog?limit=9999')->assertStatus(422);
});

// ----------------------------------------------------------------- кеш

it('обслуживает повторный запрос из кеша', function () {
    $sku = $this->getJson('/api/catalog?limit=1')->json('items.0.sku');

    // Меняем остаток мимо приложения: кеш об этом знать не может.
    DB::table('product_stock')->where('sku', $sku)->update(['available' => 777]);

    expect($this->getJson('/api/catalog?limit=1')->json('items.0.available'))->not->toBe(777);
});

it('сбрасывает кеш при изменении остатка', function () {
    $sku = $this->getJson('/api/catalog?limit=1')->json('items.0.sku');

    DB::table('product_stock')->where('sku', $sku)->update(['available' => 777]);
    app(CatalogCache::class)->invalidate();

    expect($this->getJson('/api/catalog?limit=1')->json('items.0.available'))->toBe(777);
});

it('выдача заказа сбрасывает кеш витрины', function () {
    useInProcessSupplier();

    $before = app(CatalogCache::class)->version();
    $order = paidOrder();

    expect($order->refresh()->status->value)->toBe('delivered')
        // Остаток уменьшился в транзакции выдачи — витрина устарела.
        ->and(app(CatalogCache::class)->version())->toBeGreaterThan($before);
});

it('пополнение остатка сбрасывает кеш витрины', function () {
    $before = app(CatalogCache::class)->version();

    $this->postJson('/api/admin/stock/KEY-CS2-PRIME/refill', ['quantity' => 5])->assertOk();

    expect(app(CatalogCache::class)->version())->toBeGreaterThan($before);
});

it('витрина показывает уменьшившийся остаток после выдачи', function () {
    useInProcessSupplier();

    $sku = 'KEY-CS2-PRIME';
    $before = (int) DB::table('product_stock')->where('sku', $sku)->value('available');

    paidOrder($sku);

    $item = collect($this->getJson('/api/catalog')->json('items'))->firstWhere('sku', $sku);

    expect($item['available'])->toBe($before - 1);
});

// -------------------------------------------------- ключи, привязанные к SKU

it('универсальные ключи демо-пула достаются любому SKU', function () {
    useInProcessSupplier();

    // Пул из docs/keys.json идёт без sku — поведение сценариев 1–6
    // от появления колонки не изменилось.
    expect(DB::table(config('gamestore.stub.schema').'.stub_keys')->whereNotNull('sku')->count())->toBe(0);

    $order = paidOrder('KEY-CS2-PRIME');

    expect($order->refresh()->status->value)->toBe('delivered');
});

it('ключ, привязанный к другому SKU, заказу не достаётся', function () {
    useInProcessSupplier();

    $keys = config('gamestore.stub.schema').'.stub_keys';
    // Весь пул A закреплён за чужим товаром, B опустошён.
    DB::table($keys)->where('supplier', 'A')->update(['sku' => 'STEAM-TOPUP-500']);
    DB::table($keys)->where('supplier', 'B')->delete();

    $order = paidOrder('KEY-CS2-PRIME');

    expect($order->refresh()->status->value)->toBe('out_of_stock');
});

it('ключ своего SKU заказу достаётся', function () {
    useInProcessSupplier();

    $keys = config('gamestore.stub.schema').'.stub_keys';
    DB::table($keys)->where('supplier', 'A')->update(['sku' => 'KEY-CS2-PRIME']);

    $order = paidOrder('KEY-CS2-PRIME');

    expect($order->refresh()->status->value)->toBe('delivered')
        ->and(Order::findOrFail($order->id)->delivery->supplier)->toBe('A');
});
