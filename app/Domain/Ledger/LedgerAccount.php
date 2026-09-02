<?php

namespace App\Domain\Ledger;

enum LedgerAccount: string
{
    case Customer = 'customer';
    case MerchantRevenue = 'merchant_revenue';
    case Cogs = 'cogs';
    case Inventory = 'inventory';
}
