<?php

use App\Domain\Stub\StubIssuer;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\KeyPoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
