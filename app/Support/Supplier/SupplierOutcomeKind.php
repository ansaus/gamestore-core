<?php

namespace App\Support\Supplier;

enum SupplierOutcomeKind
{
    /** Код получен. */
    case Succeeded;

    /** Определённый отказ: 4xx, out_of_stock. Поставщик точно ничего не выдал. */
    case Rejected;

    /**
     * Неопределённость: 5xx, таймаут, обрыв сети.
     * Поставщик МОГ выдать код — ответ просто не дошёл. Таймаут ≠ отказ.
     */
    case Unknown;
}
