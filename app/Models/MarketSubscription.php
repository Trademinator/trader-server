<?php

namespace App\Models;

use App\Events\MarketSubscriptionCreated;
use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MarketSubscription extends Model
{
    use HasUniqueIdentifier;

    protected $primaryKey = 'market_subscription_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['user_id', 'market_id', 'active'];

    protected static function booted(): void
    {
        static::created(fn (self $subscription) => event(new MarketSubscriptionCreated($subscription)));
    }

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

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'dashboard_market_favorites', 'market_subscription_id', 'user_id',
            'market_subscription_id', 'user_id')->withTimestamps();
    }

    public function clientSetting(): HasOne
    {
        return $this->hasOne(ClientMarketSetting::class, 'market_subscription_id', 'market_subscription_id');
    }

    public function clientExecutionReports(): HasMany
    {
        return $this->hasMany(ClientExecutionReport::class, 'market_subscription_id', 'market_subscription_id');
    }

    public function paperAccount(): HasOne
    {
        return $this->hasOne(ClientPaperAccount::class, 'market_subscription_id', 'market_subscription_id');
    }
}
