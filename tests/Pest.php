<?php

use App\Domain\Order\Order;
use App\Domain\Stub\StubConfig;
use App\Domain\Stub\StubIssuer;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\KeyPoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/** Каталог и пул ключей заглушки — как на боевом сиде. */
function seedShop(): void
{
    test()->seed(CatalogSeeder::class);
    test()->seed(KeyPoolSeeder::class);
}

/**
 * Заворачивает HTTP-вызовы поставщика в настоящую заглушку внутри процесса.
 *
 * Ходить по сети в тестах нельзя: заглушка живёт в отдельном контейнере и
 * работает с dev-базой, а тест — со своей, внутри незакоммиченной транзакции.
 * Поэтому подменяем транспорт, но не логику: StubIssuer тот же самый.
 */
function useInProcessSupplier(): void
{
    Http::fake(function (Request $request) {
        $body = $request->data();
        $supplier = str_contains($request->url(), '/supplier/b/') ? 'B' : 'A';

        $result = app(StubIssuer::class)->issue(
            $supplier,
            $body['request_id'],
            $body['order_id'],
            $body['sku'],
        );

        // force=timeout_after_issue: выдача уже в БД, а ответ до нас не дошёл.
        // В проде это 5 секунд сна и таймаут клиента; в тесте спать эти секунды
        // на каждой попытке незачем — важна механика, а не длительность паузы.
        // Повтор тем же request_id пойдёт по ветке replayed и ответ получит.
        $hangs = StubConfig::for($supplier)['force'] === 'timeout_after_issue';

        if ($hangs && $result->status === 200 && ! $result->replayed) {
            throw new ConnectionException('cURL error 28: Operation timed out');
        }

        if ($result->status !== 200) {
            return Http::response(['status' => 'error', 'reason' => $result->reason], $result->status);
        }

        return Http::response([
            'status' => 'ok',
            'request_id' => $body['request_id'],
            'code' => $result->code,
            'replayed' => $result->replayed,
        ]);
    });
}

/**
 * Каким будет id следующего созданного заказа.
 *
 * Нужен тестам про сироту: событие приходит раньше заказа, а значит его
 * order_id надо знать заранее. Захардкодить ord_00001 нельзя — nextval()
 * не откатывается вместе с транзакцией теста и растёт по всему прогону.
 */
function nextOrderId(): string
{
    $seq = DB::selectOne('select last_value, is_called from orders_public_id_seq');
    $next = (int) $seq->last_value + ($seq->is_called ? 1 : 0);

    return config('gamestore.order_id_prefix').str_pad((string) $next, 5, '0', STR_PAD_LEFT);
}

/** Создаёт заказ и доводит его до оплаты. */
function paidOrder(string $sku = 'KEY-CS2-PRIME'): Order
{
    $id = test()->postJson('/api/orders', ['sku' => $sku])->json('id');
    test()->postJson('/api/webhooks/payment', webhookPayload($id))->assertOk();

    return Order::findOrFail($id);
}

/** @param array<string, mixed> $overrides */
function webhookPayload(string $orderId, array $overrides = []): array
{
    return array_merge([
        'event_id' => 'evt_'.Str::random(10),
        'order_id' => $orderId,
        'status' => 'paid',
        'amount' => 1290,
        'currency' => 'RUB',
        'created_at' => '2026-09-02T12:00:00Z',
    ], $overrides);
}
