<?php

namespace App\Domain\Delivery;

use Illuminate\Database\Eloquent\Model;

class UnclaimedCode extends Model
{
    protected $table = 'unclaimed_codes';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
