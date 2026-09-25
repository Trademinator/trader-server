<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketFeed extends Model
{
    protected $primaryKey = 'market_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['market_id', 'selected_period', 'status', 'next_pull_at', 'last_pulled_at', 'lease_until', 'lease_token', 'last_error'];

    protected function casts(): array
    {
        return ['next_pull_at' => 'datetime', 'last_pulled_at' => 'datetime', 'lease_until' => 'datetime'];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_id', 'market_id');
    }
}
