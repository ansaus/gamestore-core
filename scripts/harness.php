<?php

/**
 * Общая обвязка скриптов приёмки из /scripts.
 *
 * Все они работают одинаково: бьют по НАСТОЯЩЕМУ HTTP через nginx в поднятый
 * стек с живым воркером, а Laravel поднимают только ради доступа к БД в
 * проверке инвариантов. Ненулевой код возврата при нарушении — чтобы годилось
 * в CI без обёрток.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// ---------------------------------------------------------------- аргументы

/** @return array<string, string> */
function parseArgs(array $argv): array
{
    $opts = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (! preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
            fail("Не понимаю аргумент: {$arg}");
        }

        $opts[$m[1]] = $m[2] ?? '1';
    }

    return $opts;
}

function fail(string $message): never
{
    fwrite(STDERR, "\n  ОШИБКА: {$message}\n\n");
    exit(1);
}

function storeUrl(string $path = ''): string
{
    return rtrim((string) config('gamestore.internal_base_url'), '/').$path;
}

function stubUrl(string $path = ''): string
{
    return rtrim((string) config('gamestore.supplier.base_url'), '/').$path;
}

// ---------------------------------------------------------------------- HTTP

/**
 * @param  array<string, mixed>|null  $body
 * @return array<int, mixed>
 */
function curlOptions(string $method, string $url, ?array $body, array $headers = []): array
{
    return [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => array_merge(
            ['Content-Type: application/json', 'Accept: application/json'],
            $headers,
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
    ];
}

/**
 * @param  array<string, mixed>|null  $body
 * @return array{status: int, body: array<string, mixed>|null, error: string|null}
 */
function request(string $method, string $url, ?array $body = null, array $headers = []): array
{
    $ch = curl_init();
    curl_setopt_array($ch, curlOptions($method, $url, $body, $headers));

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;
    curl_close($ch);

    return [
        'status' => $status,
        'body' => is_string($raw) ? json_decode($raw, true) : null,
        'error' => $error,
    ];
}

/** @param array<int|string, int> $counts */
function formatCounts(array $counts): string
{
    if ($counts === []) {
        return 'нет';
    }

    return implode(', ', array_map(
        static fn ($key, $count) => "{$key}×{$count}",
        array_keys($counts),
        $counts,
    ));
}

// ------------------------------------------------------------------ заглушка

/** @param array<string, mixed> $values */
function stubConfig(string $supplier, array $values): void
{
    $response = request('POST', stubUrl('/stub/config'), ['supplier' => $supplier] + $values);

    if ($response['status'] !== 200) {
        fail("заглушка не приняла конфиг для {$supplier}: HTTP {$response['status']}");
    }
}

/**
 * Требует, чтобы у поставщика оставались свободные ключи.
 *
 * Сценарии СОЗНАТЕЛЬНО не сбрасывают пул заглушки: сброс освободил бы ключи,
 * уже проданные прежним заказам, и поставщик начал бы выдавать коды, которые
 * в deliveries уже есть. В проде такого не бывает, а вердикт сценария из-за
 * этого стал бы ложным. Пул сбрасывается только вместе с доменом — `make fresh`.
 */
function requireFreeKeys(string $supplier, int $min = 1): void
{
    $free = DB::table(config('gamestore.stub.schema').'.stub_keys')
        ->where('supplier', strtoupper($supplier))
        ->where('status', 'free')
        ->count();

    if ($free < $min) {
        fail("у поставщика {$supplier} свободных ключей {$free}, нужно минимум {$min}. "
            .'Пул и домен сбрасываются вместе: make fresh');
    }
}

// -------------------------------------------------------------------- заказы

function requireHealthyStore(): void
{
    $health = request('GET', storeUrl('/up'));

    if ($health['status'] !== 200) {
        fail('магазин не отвечает на '.storeUrl('/up')." (HTTP {$health['status']}). "
            .'Поднят ли стек: docker compose ps');
    }
}

/** @return object заказ из БД */
function createOrder(string $sku): object
{
    $created = request('POST', storeUrl('/api/orders'), ['sku' => $sku], [
        'Idempotency-Key: scenario-'.bin2hex(random_bytes(8)),
    ]);

    if ($created['status'] !== 201 || ! isset($created['body']['id'])) {
        fail("не удалось создать заказ по sku={$sku}: HTTP {$created['status']}");
    }

    return DB::table('orders')->where('id', $created['body']['id'])->first();
}

function payOrder(object $order): void
{
    $response = request('POST', storeUrl('/api/webhooks/payment'), [
        'event_id' => 'evt_'.bin2hex(random_bytes(6)),
        'order_id' => $order->id,
        'status' => 'paid',
        'amount' => $order->amount,
        'currency' => $order->currency,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);

    if ($response['status'] !== 200 || ($response['body']['result'] ?? null) !== 'applied') {
        fail("оплата заказа {$order->id} не применена: HTTP {$response['status']} "
            .json_encode($response['body'], JSON_UNESCAPED_UNICODE));
    }
}

/**
 * Ждёт, пока воркер доведёт заказ до одного из статусов.
 *
 * @param  list<string>  $statuses
 */
function waitForStatus(string $orderId, array $statuses, int $seconds = 30): string
{
    $deadline = microtime(true) + $seconds;

    do {
        $status = (string) DB::table('orders')->where('id', $orderId)->value('status');

        if (in_array($status, $statuses, true)) {
            return $status;
        }

        usleep(200_000);
    } while (microtime(true) < $deadline);

    printf("\n  Заказ %s за %d с не дошёл до [%s], застряв в '%s'.\n",
        $orderId, $seconds, implode('|', $statuses), $status);
    printf("  Обрабатывает ли очередь воркер: docker compose logs --tail=50 worker\n");

    return $status;
}

// ------------------------------------------------------------------ проверки

final class Verdict
{
    /** @var list<array{ok: bool, label: string, detail: string}> */
    private array $checks = [];

    public function check(bool $ok, string $label, string $detail = ''): void
    {
        $this->checks[] = ['ok' => $ok, 'label' => $label, 'detail' => $detail];
    }

    public function expect(string $label, mixed $expected, mixed $actual): void
    {
        $this->check(
            $expected === $actual,
            $label,
            $expected === $actual
                ? var_export($actual, true)
                : 'ожидалось '.var_export($expected, true).', получено '.var_export($actual, true),
        );
    }

    /** Печатает отчёт и возвращает код возврата процесса. */
    public function report(): int
    {
        $passed = true;

        echo "\n";

        foreach ($this->checks as $check) {
            $mark = $check['ok'] ? '  OK  ' : ' FAIL ';
            $detail = $check['detail'] === '' ? '' : "  — {$check['detail']}";
            printf("  [%s] %s%s\n", $mark, $check['label'], $detail);
            $passed = $passed && $check['ok'];
        }

        printf("\n  %s\n\n", $passed ? 'ИНВАРИАНТЫ ЦЕЛЫ' : 'ИНВАРИАНТ НАРУШЕН');

        return $passed ? 0 : 1;
    }
}
