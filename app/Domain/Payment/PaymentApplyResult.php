<?php

namespace App\Domain\Payment;

enum PaymentApplyResult: string
{
    /** Событие применено, статус заказа изменился. */
    case Applied = 'applied';

    /** event_id уже был — существующая строка не тронута. В БД не хранится. */
    case Duplicate = 'duplicate';

    /** Заказа ещё нет: событие пришло раньше заказа, ждёт подхвата. */
    case Orphan = 'orphan';

    /** Отказ после подтверждённой оплаты — статус назад не откатываем (I5). */
    case Stale = 'stale';

    /** Заказ уже в этом или более позднем состоянии, делать нечего. */
    case IgnoredTerminal = 'ignored_terminal';
}
