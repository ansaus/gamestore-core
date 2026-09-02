<?php

namespace App\Domain\Ledger;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $table = 'ledger_entries';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];
}
