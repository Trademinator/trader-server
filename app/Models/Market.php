<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Market extends Model
{
    use HasUniqueIdentifier;

    protected $primaryKey = 'market_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['exchange_id', 'symbol', 'tick_size'];

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class, 'exchange_id', 'exchange_id');
    }

    public function feed(): HasOne
    {
        return $this->hasOne(MarketFeed::class, 'market_id', 'market_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(MarketSubscription::class, 'market_id', 'market_id');
    }

    public function coinGeckoMapping(): HasOne
    {
        return $this->hasOne(CoinGeckoMarketMapping::class, 'market_id', 'market_id');
    }
}
