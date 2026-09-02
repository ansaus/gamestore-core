<?php

namespace App\Domain\Ledger;

enum LedgerRefType: string
{
    case PaymentEvent = 'payment_event';
    case Delivery = 'delivery';
}
