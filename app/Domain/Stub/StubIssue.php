<?php

namespace App\Domain\Stub;

use Illuminate\Database\Eloquent\Model;

class StubIssue extends Model
{
    protected $primaryKey = 'request_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('gamestore.stub.schema').'.stub_issues';
    }
}
