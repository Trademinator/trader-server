<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientApiKey extends Model
{
    use HasUniqueIdentifier;

    protected $primaryKey = 'client_api_key_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['user_id', 'label', 'prefix', 'secret_hash', 'last_used_at', 'expires_at', 'revoked_at'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function active(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
