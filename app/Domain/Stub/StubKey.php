<?php

namespace App\Domain\Stub;

use Illuminate\Database\Eloquent\Model;

class StubKey extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('gamestore.stub.schema').'.stub_keys';
    }
}
