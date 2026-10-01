<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Database\Factories\ExchangeCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeCredential extends Model
{
    /** @use HasFactory<ExchangeCredentialFactory> */
    use HasFactory, HasUniqueIdentifier;

    protected $primaryKey = 'exchange_credential_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['user_id', 'exchange_id', 'credentials', 'is_shared'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'is_shared' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class, 'exchange_id', 'exchange_id');
    }
}
