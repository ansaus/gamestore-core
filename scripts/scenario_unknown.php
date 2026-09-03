<?php

/**
 * Обратная сторона фолбэка (SPEC §7.3) — правило, ради которого весь этап 3.
 *
 * A отвечает 500. Это НЕ отказ: поставщик мог успеть выдать код до падения.
 * Пока его состояние unknown, уходить к B запрещено — иначе за один заказ
 * спишутся два ключа. Заказ остаётся в delivering, получает next_attempt_at
 * и ждёт следующего цикла, где его допытывают тем же request_id.
 *
 * Проверяем ровно это: к B не ходили, заявки к B нет, заказ не провален.
 *
 *   php scripts/scenario_unknown.php
 *
 * Код возврата: 0 — инварианты целы, 1 — нарушение.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/harness.php';

/**
 * Ждёт, пока заказ перестанет меняться.
 *
 * Обычные сценарии ждут терминального статуса, а здесь правильный исход —
 * его отсутствие: заказ должен ОСТАТЬСЯ в delivering.
 */
function waitForStatusStable(string $orderId, int $seconds): string
{
    $deadline = microtime(true) + $seconds;

    do {
        $order = DB::table('orders')->where('id', $orderId)->first();

        if ($order->status === 'delivering' && $order->next_attempt_at !== null) {
            return $order->status;
        }

        if (in_array($order->status, ['delivered', 'out_of_stock', 'delivery_failed'], true)) {
            return $order->status;
        }

        usleep(200_000);
    } while (microtime(true) < $deadline);

    return (string) $order->status;
}

$opts = parseArgs($argv);
$sku = $opts['sku'] ?? 'KEY-CS2-PRIME';
$waitSec = (int) ($opts['wait'] ?? 30);

echo "\n  Сценарий: A отвечает 5xx → состояние unknown, фолбэк на B ЗАПРЕЩЁН\n\n";

requireHealthyStore();
requireFreeKeys('A');

stubConfig('A', ['force' => 'http_500']);
stubConfig('B', ['force' => 'none']);

try {
    $order = createOrder($sku);
    printf("  Заказ %s  sku=%s\n", $order->id, $order->sku);

    payOrder($order);
    printf("  Оплачен. Ждём, пока клиент исчерпает %d попытки к A...\n",
        (int) config('gamestore.supplier.max_attempts'));

    // Заказ обязан остаться в delivering: терминального статуса тут не будет.
    $status = waitForStatusStable($order->id, $waitSec);

    $stub = config('gamestore.stub.schema');
    $requests = DB::table('supplier_requests')->where('order_id', $order->id)->get()->keyBy('supplier');
    $fresh = DB::table('orders')->where('id', $order->id)->first();

    $verdict = new Verdict;

    // Главное правило этапа 3.
    $verdict->expect(
        'Заявок к B',
        0,
        DB::table('supplier_requests')->where('order_id', $order->id)->where('supplier', 'B')->count(),
    );
    $verdict->expect(
        'Ключей, занятых у B',
        0,
        DB::table("{$stub}.stub_keys")->where('request_id', "req_{$order->id}_B")->count(),
    );
    $verdict->expect('Всего выдач у заглушки по заказу', 0,
        DB::table("{$stub}.stub_issues")->where('order_id', $order->id)->count());

    // Заказ не провален: исход просто не выяснен.
    $verdict->expect('Статус заказа', 'delivering', $status);
    $verdict->expect('Состояние заявки к A', 'unknown', $requests['A']->state ?? null);
    $verdict->check(
        $fresh->next_attempt_at !== null,
        'Назначен следующий цикл (next_attempt_at)',
        (string) ($fresh->next_attempt_at ?? 'null'),
    );
    $verdict->check(
        (int) ($requests['A']->attempts ?? 0) === (int) config('gamestore.supplier.max_attempts'),
        'Попыток к A ровно столько, сколько задано конфигом',
        'attempts='.(int) ($requests['A']->attempts ?? 0),
    );

    // Выдачи нет и денег за неё не признано.
    $verdict->expect('Строк в deliveries', 0,
        DB::table('deliveries')->where('order_id', $order->id)->count());

    $exit = $verdict->report();
} finally {
    stubConfig('A', ['force' => 'none']);
}

exit($exit);
