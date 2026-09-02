<?php

namespace App\Domain\Catalog;

use Illuminate\Database\Eloquent\Model;

class ProductStock extends Model
{
    protected $table = 'product_stock';

    protected $primaryKey = 'sku';

    public $incrementing = false;

    protected $keyType = 'string';

    public const CREATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'available' => 'integer',
        'updated_at' => 'datetime',
    ];
}
