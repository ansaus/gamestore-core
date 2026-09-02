<?php

namespace App\Domain\Payment;

use Illuminate\Database\Eloquent\Model;

class PaymentEvent extends Model
{
    protected $table = 'payment_events';

    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'status' => PaymentStatus::class,
        'apply_result' => PaymentApplyResult::class,
        'amount' => 'decimal:2',
        'amount_mismatch' => 'boolean',
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'applied_at' => 'datetime',
    ];
}
