<?php

/**
 * Сценарий приёмки 6 (SPEC §10): пустой остаток и восстановление.
 *
 * Оба поставщика отказывают определённо — заказ обязан уйти в out_of_stock,
 * а НЕ упасть 5xx: кончившийся товар это штатная ситуация, а не сбой. Сервис
 * при этом продолжает принимать заказы.
 *
 * Дальше поставщик оживает, приходит POST /api/admin/stock/{sku}/refill —
 * и заказ доводит до delivered фоновая задача, без единого ручного действия.
 *
 *   php scripts/scenario_oos.php
 *
 * Код возврата: 0 — инварианты целы, 1 — нарушение.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/harness.php';

$opts = parseArgs($argv);
$sku = $opts['sku'] ?? 'KEY-CS2-PRIME';
$waitSec = (int) ($opts['wait'] ?? 150);

echo "\n  Сценарий 6: пустой остаток → out_of_stock → пополнение → delivered\n\n";

requireHealthyStore();
requireFreeKeys('A');

// Оба поставщика отказывают ОПРЕДЕЛЁННО: ключ не занят и не будет.
stubConfig('A', ['force' => 'out_of_stock']);
stubConfig('B', ['force' => 'out_of_stock']);

try {
    $order = createOrder($sku);
    printf("  Заказ %s  sku=%s\n", $order->id, $order->sku);

    payOrder($order);
    echo "  Оплачен. Оба поставщика пусты — ждём out_of_stock...\n";

    $status = waitForStatus($order->id, ['out_of_stock', 'delivered', 'delivery_failed'], 60);

    $verdict = new Verdict;
    $verdict->expect('Статус после пустого пула', 'out_of_stock', $status);
    $verdict->expect('Строк в deliveries', 0,
        DB::table('deliveries')->where('order_id', $order->id)->count());

    // Сервис жив: 5xx нет, новые заказы принимаются.
    $health = request('GET', storeUrl('/up'));
    $probe = request('POST', storeUrl('/api/orders'), ['sku' => $sku], [
        'Idempotency-Key: oos-probe-'.bin2hex(random_bytes(6)),
    ]);
    $verdict->expect('Сервис отвечает на /up', 200, $health['status']);
    $verdict->expect('Новые заказы принимаются', 201, $probe['status']);

    // Заказ восстановим: у него назначен следующий цикл.
    $parked = DB::table('orders')->where('id', $order->id)->first();
    $verdict->check($parked->next_attempt_at !== null,
        'Назначен следующий цикл (next_attempt_at)', (string) $parked->next_attempt_at);

    // --- восстановление -----------------------------------------------------

    echo "\n  Поставщик A ожил, пополняем витрину...\n";
    stubConfig('A', ['force' => 'none']);

    $before = (int) DB::table('product_stock')->where('sku', $sku)->value('available');
    $refill = request('POST', storeUrl("/api/admin/stock/{$sku}/refill"), ['quantity' => 10]);

    $verdict->expect('Ответ refill', 200, $refill['status']);
    $verdict->check(
        ($refill['body']['woken_orders'] ?? 0) >= 1,
        'Пополнение разбудило ждущие заказы',
        'woken_orders='.($refill['body']['woken_orders'] ?? 0),
    );

    printf("  Витрина: было %d, стало %d. Ждём фоновое доведение (тик раз в минуту)...\n",
        $before, (int) ($refill['body']['available'] ?? 0));

    // Никаких ручных retry: доводит именно ReconcileStuckOrders.
    $status = waitForStatus($order->id, ['delivered', 'delivery_failed'], $waitSec);

    $delivery = DB::table('deliveries')->where('order_id', $order->id)->first();

    $verdict->expect('Статус после пополнения', 'delivered', $status);
    $verdict->expect('Строк в deliveries', 1,
        DB::table('deliveries')->where('order_id', $order->id)->count());
    $verdict->check($delivery !== null && $delivery->code !== '',
        'Код выдан', (string) ($delivery->code ?? '—'));

    // Витрина списалась ровно на одну штуку сверх пополнения.
    $verdict->expect(
        'Остаток витрины',
        $before + 10 - 1,
        (int) DB::table('product_stock')->where('sku', $sku)->value('available'),
    );

    // I6 по заказу: журнал сходится.
    $sums = DB::selectOne("
        select coalesce(sum(amount) filter (where direction = 'debit'), 0)::text as debit,
               coalesce(sum(amount) filter (where direction = 'credit'), 0)::text as credit
        from ledger_entries where order_id = ?
    ", [$order->id]);

    $verdict->check(
        bccomp($sums->debit, $sums->credit, 2) === 0,
        'Журнал по заказу сходится',
        "debit={$sums->debit} credit={$sums->credit}",
    );

    $exit = $verdict->report();
} finally {
    stubConfig('A', ['force' => 'none']);
    stubConfig('B', ['force' => 'none']);
}

exit($exit);
