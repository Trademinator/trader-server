<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketSubscription extends Model
{
    use HasUniqueIdentifier;

    protected $primaryKey = 'market_subscription_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['user_id', 'market_id', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_id', 'market_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
