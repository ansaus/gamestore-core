<?php

/**
 * Сценарии приёмки 1 и 1b (SPEC §10): N параллельных вебхуков по одному заказу.
 *
 * Запросы уходят по НАСТОЯЩЕМУ HTTP через nginx в работающий стек с живым
 * воркером. Это принципиально: гонку, которую мы проверяем, создают параллельные
 * php-fpm воркеры, борющиеся за одну строку заказа в Postgres. Внутрипроцессный
 * тест такую гонку не воспроизводит вообще — там всё последовательно.
 *
 * Барьер старта: все хендлы добавляются в multi-хендл до первого
 * curl_multi_exec(). Ни один запрос не уходит, пока не готовы все.
 *
 * Ларавел здесь поднимается только ради доступа к БД в проверке инвариантов.
 *
 *   php scripts/race.php --n=50 --mode=distinct-events
 *   php scripts/race.php --n=50 --mode=same-event
 *   php scripts/race.php --n=50 --mode=mixed --sku=KEY-CS2-PRIME
 *
 * Код возврата: 0 — все инварианты целы, 1 — нарушение (годится для CI).
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const MODES = ['same-event', 'distinct-events', 'mixed'];

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

// ---------------------------------------------------------------------- HTTP

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
 * Собственно гонка.
 *
 * @param  list<array<string, mixed>>  $payloads
 * @return array{results: list<array{status: int, result: string|null, error: string|null}>, elapsed_ms: int}
 */
function race(string $url, array $payloads): array
{
    $mh = curl_multi_init();
    $handles = [];

    // Фаза 1: собрать все хендлы. Сеть при этом молчит.
    foreach ($payloads as $i => $payload) {
        $ch = curl_init();
        curl_setopt_array($ch, curlOptions('POST', $url, $payload));
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    // Фаза 2: единый старт. Все запросы уходят с одного curl_multi_exec.
    $startedAt = hrtime(true);

    do {
        $status = curl_multi_exec($mh, $running);

        if ($running > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

    $results = [];

    foreach ($handles as $i => $ch) {
        $raw = curl_multi_getcontent($ch);
        $body = is_string($raw) ? json_decode($raw, true) : null;

        $results[$i] = [
            'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'result' => is_array($body) && isset($body['result']) ? (string) $body['result'] : null,
            'error' => curl_errno($ch) !== 0 ? curl_error($ch) : null,
        ];

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);

    return ['results' => $results, 'elapsed_ms' => $elapsedMs];
}

// ------------------------------------------------------------------ payload

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

/**
 * @return list<array<string, mixed>>
 */
function buildPayloads(string $mode, int $n, string $orderId, string $amount, string $currency): array
{
    $run = bin2hex(random_bytes(4));
    $shared = "evt_{$run}_shared";
    $occurredAt = gmdate('Y-m-d\TH:i:s\Z');

    $event = static fn (string $eventId, string $status): array => [
        'event_id' => $eventId,
        'order_id' => $orderId,
        'status' => $status,
        'amount' => $amount,
        'currency' => $currency,
        'created_at' => $occurredAt,
    ];

    $payloads = [];

    for ($i = 0; $i < $n; $i++) {
        $payloads[] = match (true) {
            // 1b: один и тот же event_id N раз. Дедупликация — на PK payment_events.
            $mode === 'same-event' => $event($shared, 'paid'),

            // 1: N разных событий об оплате одного заказа. Дедупликации нет,
            // всё держится на FOR UPDATE по строке заказа и условном UPDATE.
            $mode === 'distinct-events' => $event("evt_{$run}_{$i}", 'paid'),

            // mixed: и повторы, и разные события, и опоздавшие failed вперемешку.
            // Проверяем главное правило: paid сильнее failed при любом порядке.
            $i % 5 === 0 => $event($shared, 'paid'),
            $i % 5 === 1 => $event("evt_{$run}_{$i}_failed", 'failed'),
            default => $event("evt_{$run}_{$i}", 'paid'),
        };
    }

    return $payloads;
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
            $expected === $actual ? (string) $actual : "ожидалось {$expected}, получено {$actual}",
        );
    }

    public function print(): bool
    {
        $passed = true;

        foreach ($this->checks as $check) {
            $mark = $check['ok'] ? '  OK  ' : ' FAIL ';
            $detail = $check['detail'] === '' ? '' : "  — {$check['detail']}";
            printf("  [%s] %s%s\n", $mark, $check['label'], $detail);
            $passed = $passed && $check['ok'];
        }

        return $passed;
    }
}

// ---------------------------------------------------------------------- прогон

$opts = parseArgs($argv);
$mode = $opts['mode'] ?? 'distinct-events';
$n = (int) ($opts['n'] ?? 50);
$sku = $opts['sku'] ?? 'KEY-CS2-PRIME';
$waitSec = (int) ($opts['wait'] ?? 30);
$base = rtrim((string) config('gamestore.internal_base_url'), '/');

if (! in_array($mode, MODES, true)) {
    fail('--mode должен быть одним из: '.implode(', ', MODES));
}

if ($n < 1) {
    fail('--n должен быть больше нуля');
}

printf("\n  Гонка вебхуков: mode=%s n=%d base=%s\n\n", $mode, $n, $base);

// -- 1. предполётная проверка: стек жив, ключи в пуле есть -------------------

$health = request('GET', $base.'/up');

if ($health['status'] !== 200) {
    fail("магазин не отвечает на {$base}/up (HTTP {$health['status']}). Поднят ли стек: docker compose ps");
}

$stubKeys = config('gamestore.stub.schema').'.stub_keys';
$freeKeys = DB::table($stubKeys)->where('supplier', 'A')->where('status', 'free')->count();

if ($freeKeys === 0) {
    fail('в пуле поставщика A не осталось свободных ключей — выдать заказ будет нечем. '
        .'Сбросьте заглушку: docker compose exec -T app php artisan stub:reset');
}

// -- 2. заказ ----------------------------------------------------------------

if (isset($opts['order'])) {
    $orderId = $opts['order'];
    $order = DB::table('orders')->where('id', $orderId)->first();

    if ($order === null) {
        fail("заказ {$orderId} не найден");
    }

    // Иначе вердикт нечего проверять: «ровно одно применённое событие»
    // осмысленно только для заказа, который ещё никто не оплатил.
    if ($order->status !== 'created') {
        fail("заказ {$orderId} в статусе {$order->status}, а гонка имеет смысл только для created");
    }
} else {
    $created = request('POST', $base.'/api/orders', ['sku' => $sku], [
        'Idempotency-Key: race-'.bin2hex(random_bytes(8)),
    ]);

    if ($created['status'] !== 201 || ! isset($created['body']['id'])) {
        fail("не удалось создать заказ по sku={$sku}: HTTP {$created['status']}");
    }

    $orderId = (string) $created['body']['id'];
    $order = DB::table('orders')->where('id', $orderId)->first();
}

printf("  Заказ %s  sku=%s  amount=%s %s\n\n", $orderId, $order->sku, $order->amount, $order->currency);

// -- 3. залп -----------------------------------------------------------------

$payloads = buildPayloads($mode, $n, $orderId, (string) $order->amount, (string) $order->currency);
$distinctEvents = count(array_unique(array_column($payloads, 'event_id')));

['results' => $results, 'elapsed_ms' => $elapsedMs] = race($base.'/api/webhooks/payment', $payloads);

$byResult = array_count_values(array_filter(array_column($results, 'result')));
ksort($byResult);
$httpCodes = array_count_values(array_column($results, 'status'));
ksort($httpCodes);

printf("  Отстрелялись за %d мс. HTTP: %s\n", $elapsedMs, formatCounts($httpCodes));
printf("  Ответы вебхука: %s\n\n", formatCounts($byResult));

// -- 4. ждём воркера ---------------------------------------------------------

$settled = ['delivered', 'out_of_stock', 'delivery_failed', 'payment_failed'];
$deadline = microtime(true) + $waitSec;
$status = null;

do {
    $status = (string) DB::table('orders')->where('id', $orderId)->value('status');

    if (in_array($status, $settled, true)) {
        break;
    }

    usleep(200_000);
} while (microtime(true) < $deadline);

if (! in_array($status, $settled, true)) {
    printf("  Заказ застрял в статусе '%s' за %d с — обрабатывает ли очередь воркер?\n", $status, $waitSec);
    printf("  Проверьте: docker compose ps worker && docker compose logs --tail=50 worker\n\n");
}

// -- 5. вердикт --------------------------------------------------------------

$verdict = new Verdict;

// Общее для всех режимов: сколько бы вебхуков ни пришло, выдача ровно одна.
$verdict->expect('Все ответы 200', $n, $httpCodes[200] ?? 0);
$verdict->expect('Сетевых ошибок нет', 0, count(array_filter(array_column($results, 'error'))));
$verdict->expect('Строк в deliveries', 1, DB::table('deliveries')->where('order_id', $orderId)->count());
$verdict->expect('Итоговый статус заказа', 'delivered', $status);

$paymentEntries = DB::table('ledger_entries')
    ->where('order_id', $orderId)
    ->where('ref_type', 'payment_event')
    ->count();

$verdict->expect('Проводок оплаты (одна пара)', 2, $paymentEntries);

$sums = DB::table('ledger_entries')
    ->where('order_id', $orderId)
    ->selectRaw("coalesce(sum(amount) filter (where direction = 'debit'), 0) as debit")
    ->selectRaw("coalesce(sum(amount) filter (where direction = 'credit'), 0) as credit")
    ->first();

$verdict->check(
    bccomp((string) $sums->debit, (string) $sums->credit, 2) === 0,
    'Журнал по заказу сходится',
    "debit={$sums->debit} credit={$sums->credit}",
);

$storedEvents = DB::table('payment_events')->where('order_id', $orderId)->count();
$verdict->expect('Событий в журнале', $distinctEvents, $storedEvents);

$appliedPaid = DB::table('payment_events')
    ->where('order_id', $orderId)
    ->where('status', 'paid')
    ->where('apply_result', 'applied')
    ->count();

$verdict->expect('Событий оплаты применено', 1, $appliedPaid);

$verdict->expect(
    'Событий без разбора не осталось',
    0,
    DB::table('payment_events')->where('order_id', $orderId)->whereNull('apply_result')->count(),
);

// Режим-специфичное: как именно распределились ответы.
match ($mode) {
    // 1b: одно применённое, остальные — дубли по PK payment_events.
    'same-event' => (function () use ($verdict, $byResult, $n): void {
        $verdict->expect('applied', 1, $byResult['applied'] ?? 0);
        $verdict->expect('duplicate', $n - 1, $byResult['duplicate'] ?? 0);
    })(),

    // 1: дублей нет вовсе, гонку разруливаетусловный UPDATE по статусу.
    'distinct-events' => (function () use ($verdict, $byResult, $n): void {
        $verdict->expect('applied', 1, $byResult['applied'] ?? 0);
        $verdict->expect('ignored_terminal', $n - 1, $byResult['ignored_terminal'] ?? 0);
        $verdict->expect('duplicate', 0, $byResult['duplicate'] ?? 0);
    })(),

    // mixed: failed'ы пришли вперемешку с paid. Отказ мог примениться только
    // один раз и только до оплаты (created → payment_failed); всё, что пришло
    // после подтверждённой оплаты, обязано быть stale — иначе откат статуса (I5).
    'mixed' => (function () use ($verdict, $orderId): void {
        $failed = DB::table('payment_events')
            ->where('order_id', $orderId)
            ->where('status', 'failed')
            ->selectRaw('apply_result, count(*) as n')
            ->groupBy('apply_result')
            ->pluck('n', 'apply_result');

        $applied = (int) ($failed['applied'] ?? 0);
        $total = (int) $failed->sum();

        $verdict->check(
            $applied <= 1,
            'Отказ применён не больше одного раза (и только до оплаты)',
            "applied={$applied}",
        );
        $verdict->expect(
            'Отказы после оплаты помечены stale',
            $total - $applied,
            (int) ($failed['stale'] ?? 0),
        );
    })(),
};

echo "\n";
$passed = $verdict->print();

printf("\n  %s\n\n", $passed
    ? 'ИНВАРИАНТЫ ЦЕЛЫ'
    : 'ИНВАРИАНТ НАРУШЕН');

exit($passed ? 0 : 1);
