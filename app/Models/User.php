<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUniqueIdentifier, Notifiable;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'email',
        'password',
        'timezone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'dashboard_seen_at_ms' => 'integer',
            'password' => 'hashed',
            'suspended_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function isOwner(): bool
    {
        return in_array(strtolower((string) $this->getAuthIdentifier()), self::ownerIds(), true);
    }

    /** @return list<string> */
    public static function ownerIds(): array
    {
        $owners = [config('operations.owner_uuid'), ...config('operations.owner_uuids', [])];

        return array_values(array_unique(array_map('strtolower', array_filter($owners,
            fn (mixed $id): bool => is_string($id) && Str::isUuid($id)))));
    }

    public function exchangeCredentials(): HasMany
    {
        return $this->hasMany(ExchangeCredential::class, 'user_id', 'user_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(MarketSubscription::class, 'user_id', 'user_id');
    }

    public function favoriteMarketSubscriptions(): BelongsToMany
    {
        return $this->belongsToMany(MarketSubscription::class, 'dashboard_market_favorites', 'user_id',
            'market_subscription_id', 'user_id', 'market_subscription_id')->withTimestamps();
    }

    public function clientApiKeys(): HasMany
    {
        return $this->hasMany(ClientApiKey::class, 'user_id', 'user_id');
    }

    public function clientExecutionReports(): HasMany
    {
        return $this->hasMany(ClientExecutionReport::class, 'user_id', 'user_id');
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
