<?php

/**
 * Сценарий приёмки 5 (SPEC §10): A отказал определённо → фолбэк на B.
 *
 * Фолбэк разрешён ровно потому, что отказ A ОПРЕДЕЛЁННЫЙ: 409 out_of_stock
 * означает, что ключ у A не занят и не будет. Проверяем, что выдача пришла
 * от B, она одна, и A действительно не потратил ключ.
 *
 * Зеркальный случай — «A остался unknown, к B идти нельзя» — в
 * scripts/scenario_unknown.php.
 *
 *   php scripts/scenario_fallback.php
 *
 * Код возврата: 0 — инварианты целы, 1 — нарушение.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/harness.php';

$opts = parseArgs($argv);
$sku = $opts['sku'] ?? 'KEY-CS2-PRIME';
$waitSec = (int) ($opts['wait'] ?? 60);

echo "\n  Сценарий 5: A недоступен (out_of_stock) → фолбэк на B\n\n";

requireHealthyStore();
requireFreeKeys('B');

stubConfig('A', ['force' => 'out_of_stock']);
stubConfig('B', ['force' => 'none']);

try {
    $order = createOrder($sku);
    printf("  Заказ %s  sku=%s\n", $order->id, $order->sku);

    payOrder($order);
    echo "  Оплачен. A обязан отказать, выдача должна прийти от B...\n";

    $status = waitForStatus($order->id, ['delivered', 'out_of_stock', 'delivery_failed'], $waitSec);

    $stub = config('gamestore.stub.schema');
    $delivery = DB::table('deliveries')->where('order_id', $order->id)->first();
    $requests = DB::table('supplier_requests')
        ->where('order_id', $order->id)
        ->get()
        ->keyBy('supplier');

    $verdict = new Verdict;

    $verdict->expect('Статус заказа', 'delivered', $status);
    $verdict->expect('Строк в deliveries', 1, DB::table('deliveries')->where('order_id', $order->id)->count());
    $verdict->expect('Поставщик выдачи', 'B', $delivery->supplier ?? null);

    // У B свой request_id и своя строка в supplier_requests.
    $verdict->expect('request_id выдачи', "req_{$order->id}_B", $delivery->request_id ?? null);
    $verdict->expect('Состояние заявки к A', 'failed', $requests['A']->state ?? null);
    $verdict->expect('Причина отказа A', 'out_of_stock', $requests['A']->error_reason ?? null);
    $verdict->expect('Состояние заявки к B', 'succeeded', $requests['B']->state ?? null);

    // A отказал ДО выдачи — ключ у него не потрачен.
    $verdict->expect(
        'Ключей, занятых у A',
        0,
        DB::table("{$stub}.stub_keys")->where('request_id', "req_{$order->id}_A")->count(),
    );
    $verdict->expect(
        'Ключей, занятых у B',
        1,
        DB::table("{$stub}.stub_keys")->where('request_id', "req_{$order->id}_B")->count(),
    );

    $issues = DB::table("{$stub}.stub_issues")->where('order_id', $order->id)->get();
    $verdict->expect('Всего выдач у заглушки по заказу', 1, $issues->count());
    $verdict->expect('Выдача сделана поставщиком', 'B', $issues->first()->supplier ?? null);

    $exit = $verdict->report();
} finally {
    stubConfig('A', ['force' => 'none']);
}

exit($exit);
