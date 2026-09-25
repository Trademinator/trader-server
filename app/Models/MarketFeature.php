<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketFeature extends Model
{
    protected $primaryKey = 'feature_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'microtimestamp' => 'integer', 'available_at_ms' => 'integer'];
    }
}
