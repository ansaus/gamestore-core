<?php

namespace App\Domain\Order;

use App\Domain\Catalog\Product;
use App\Domain\Delivery\Delivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $table = 'orders';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'status' => OrderStatus::class,
        'attempts' => 'integer',
        'paid_at' => 'datetime',
        'delivered_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class, 'order_id', 'id');
    }
}
