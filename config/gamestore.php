<?php

return [

    /*
    | Публичные идентификаторы заказов: ord_00123. Номер берётся из
    | последовательности Postgres — без гонок и без «придумай уникальное».
    */
    'order_id_prefix' => env('ORDER_ID_PREFIX', 'ord_'),

    'supplier' => [
        'base_url' => env('SUPPLIER_BASE_URL', 'http://nginx:90'),
        'connect_timeout' => (float) env('SUPPLIER_CONNECT_TIMEOUT', 1),
        'timeout' => (float) env('SUPPLIER_TIMEOUT', 2),

        /*
        | Порядок обхода поставщиков. На этапе 1 используется только первый:
        | ретраи и фолбэк A→B — этап 3.
        */
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
