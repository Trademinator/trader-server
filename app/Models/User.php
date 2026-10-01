<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
        $owner = config('operations.owner_uuid');

        return is_string($owner) && Str::isUuid($owner)
            && hash_equals(strtolower($owner), strtolower((string) $this->getAuthIdentifier()));
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(MarketSubscription::class, 'user_id', 'user_id');
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
