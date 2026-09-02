<?php

namespace App\Domain\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $table = 'products';

    protected $primaryKey = 'sku';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function stock(): HasOne
    {
        return $this->hasOne(ProductStock::class, 'sku', 'sku');
    }
}
