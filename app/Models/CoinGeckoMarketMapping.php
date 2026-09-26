<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoinGeckoMarketMapping extends Model
{
    use HasUniqueIdentifier;

    protected $primaryKey = 'coin_gecko_market_mapping_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'market_id',
        'base_symbol',
        'vs_currency',
        'coin_id',
        'coin_name',
        'category',
        'status',
        'resolved_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_id', 'market_id');
    }
}
