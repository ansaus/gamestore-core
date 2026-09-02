<?php

namespace App\Domain\Delivery;

use Illuminate\Database\Eloquent\Model;

class SupplierRequest extends Model
{
    protected $table = 'supplier_requests';

    protected $primaryKey = 'request_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'state' => SupplierRequestState::class,
        'attempts' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** request_id детерминирован и одинаков для всех попыток по этой паре. */
    public static function idFor(string $orderId, string $supplier): string
    {
        return "req_{$orderId}_".strtoupper($supplier);
    }
}
