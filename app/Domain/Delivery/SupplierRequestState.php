<?php

namespace App\Domain\Delivery;

enum SupplierRequestState: string
{
    /** Запрос ушёл, ответа ещё нет. */
    case InFlight = 'in_flight';

    /** Код получен. */
    case Succeeded = 'succeeded';

    /** Определённый отказ: 4xx, out_of_stock. Можно идти к другому поставщику. */
    case Failed = 'failed';

    /**
     * Неопределённость: 5xx, таймаут, обрыв сети. Поставщик МОГ выдать код.
     * Уходить к другому поставщику из этого состояния запрещено — задвоим ключ.
     */
    case Unknown = 'unknown';
}
