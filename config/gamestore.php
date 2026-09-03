<?php

return [

    /*
    | Публичные идентификаторы заказов: ord_00123. Номер берётся из
    | последовательности Postgres — без гонок и без «придумай уникальное».
    */
    'order_id_prefix' => env('ORDER_ID_PREFIX', 'ord_'),

    /*
    | Собственный адрес магазина изнутри сети Compose. Нужен скриптам из
    | /scripts: они бьют по настоящему HTTP через nginx, а не поднимают
    | приложение в своём процессе — иначе гонки не будет.
    */
    'internal_base_url' => env('APP_INTERNAL_URL', 'http://nginx'),

    'supplier' => [
        'base_url' => env('SUPPLIER_BASE_URL', 'http://nginx:90'),
        'connect_timeout' => (float) env('SUPPLIER_CONNECT_TIMEOUT', 1),
        'timeout' => (float) env('SUPPLIER_TIMEOUT', 2),

        /*
        | Сколько раз дёргать ОДНОГО поставщика с тем же request_id, прежде
        | чем признать исход невыясненным. Ретрай здесь работает как probe:
        | он либо вернёт ранее выданный код, либо выдаст новый, но никогда
        | не выдаст второй.
        */
        'max_attempts' => (int) env('SUPPLIER_MAX_ATTEMPTS', 3),

        // База экспоненциального бэкоффа между попытками: 200ms * 2^n.
        'backoff_ms' => (int) env('SUPPLIER_BACKOFF_MS', 200),

        /*
        | Пауза перед следующим циклом выдачи, если поставщик так и остался
        | в состоянии unknown. Заказ при этом НЕ уходит к другому поставщику
        | и НЕ считается проваленным — мы просто ещё не знаем исход.
        */
        'unknown_retry_delay' => (int) env('SUPPLIER_UNKNOWN_RETRY_DELAY', 30),

        // Сколько циклов выдачи терпим, прежде чем сдаться в delivery_failed.
        'max_delivery_cycles' => (int) env('SUPPLIER_MAX_DELIVERY_CYCLES', 5),

        // Порядок обхода: сначала A, при его ОПРЕДЕЛЁННОМ отказе — B.
        'order' => ['A', 'B'],
    ],

    'ledger' => [
        /*
        | Условная себестоимость выдачи, доля от суммы заказа. Точность цифры
        | здесь не важна — важно, что проводка парная и журнал сходится.
        */
        'cogs_rate' => (float) env('LEDGER_COGS_RATE', 0.70),
    ],

    'stub' => [
        'schema' => env('STUB_DB_SCHEMA', 'stub'),
        /*
        | Как делим пул из docs/keys.json между поставщиками:
        | первые 30 ключей → A, последние 20 → B (SPEC §2).
        */
        'key_split' => ['A' => 30, 'B' => 20],
    ],
];
