<?php

namespace App\Domain\Payment;

enum PaymentStatus: string
{
    case Paid = 'paid';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
