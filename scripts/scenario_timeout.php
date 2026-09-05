<?php

/**
 * Сценарий приёмки 4 (docs/TASK.md): поставщик ответил таймаутом, хотя код ВЫДАЛ.
 *
 * Самое неприятное место задания. Заглушка сначала фиксирует выдачу в своей
 * БД и только потом «зависает» — ответ до нас не доходит. Клиент обязан
 * повторить запрос ТЕМ ЖЕ request_id: повтор работает как probe и забирает
 * ранее выданный код вместо того, чтобы сжечь второй ключ.
 *
 * Проверяем именно это: одна запись в stub_issues, одна выдача, код тот же,
 * request_id тот же.
 *
 *   php scripts/scenario_timeout.php
 *
 * Код возврата: 0 — инварианты целы, 1 — нарушение.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/harness.php';

$opts = parseArgs($argv);
$sku = $opts['sku'] ?? 'KEY-CS2-PRIME';
$waitSec = (int) ($opts['wait'] ?? 60);

echo "\n  Сценарий 4: таймаут поставщика A после фактической выдачи кода\n\n";

requireHealthyStore();
requireFreeKeys('A');

// Клиент ждёт 2 с, заглушка «висит» дольше — таймаут гарантирован.
stubConfig('A', ['force' => 'timeout_after_issue', 'timeout_sleep_ms' => 5000]);

try {
    $order = createOrder($sku);
    printf("  Заказ %s  sku=%s\n", $order->id, $order->sku);

    payOrder($order);
    echo "  Оплачен. Первая попытка уйдёт в таймаут, дальше — ретраи тем же request_id...\n";

    $status = waitForStatus($order->id, ['delivered', 'out_of_stock', 'delivery_failed'], $waitSec);

    $requestId = "req_{$order->id}_A";
    $issues = DB::table(config('gamestore.stub.schema').'.stub_issues')
        ->where('order_id', $order->id)
        ->get();
    $delivery = DB::table('deliveries')->where('order_id', $order->id)->first();
    $request = DB::table('supplier_requests')->where('request_id', $requestId)->first();

    printf("\n  Заглушка выдала ключей: %d. Обращений к A: %d.\n",
        $issues->count(), (int) ($request->attempts ?? 0));

    $verdict = new Verdict;

    $verdict->expect('Статус заказа', 'delivered', $status);

    // Сердце сценария: сколько бы раз мы ни повторили, ключ потрачен один.
    $verdict->expect('Записей в stub_issues по заказу', 1, $issues->count());
    $verdict->expect('Строк в deliveries', 1, DB::table('deliveries')->where('order_id', $order->id)->count());

    $verdict->check(
        $delivery !== null && $issues->count() === 1 && $delivery->code === $issues->first()->code,
        'Выданный код совпадает с зафиксированным заглушкой',
        $delivery === null ? 'выдачи нет' : (string) $delivery->code,
    );

    // request_id не менялся между попытками — иначе заглушка выдала бы второй ключ.
    $verdict->expect('request_id выдачи', $requestId, $delivery->request_id ?? null);
    $verdict->expect('request_id в stub_issues', $requestId, $issues->first()->request_id ?? null);
    $verdict->expect('Поставщик', 'A', $delivery->supplier ?? null);

    $verdict->check(
        (int) ($request->attempts ?? 0) >= 2,
        'Попыток к A было больше одной (первая потерялась)',
        'attempts='.(int) ($request->attempts ?? 0),
    );
    $verdict->expect('Состояние заявки к A', 'succeeded', $request->state ?? null);

    // Ключей из пула ушло ровно столько же, сколько выдач.
    $verdict->expect(
        'Занятых ключей в пуле A',
        1,
        DB::table(config('gamestore.stub.schema').'.stub_keys')
            ->where('request_id', $requestId)
            ->count(),
    );

    // К B не ходили: A прояснился успехом, фолбэк был бы лишней тратой.
    $verdict->expect(
        'Заявок к B',
        0,
        DB::table('supplier_requests')->where('order_id', $order->id)->where('supplier', 'B')->count(),
    );

    $exit = $verdict->report();
} finally {
    // Заглушку возвращаем в исходное даже если проверка упала.
    stubConfig('A', ['force' => 'none']);
}

exit($exit);
