<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientPaperAccount extends Model
{
    use HasUniqueIdentifier;

    protected $primaryKey = 'client_paper_account_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'market_subscription_id', 'quote_balance', 'base_balance', 'initial_quote_balance',
        'benchmark_base_quantity', 'benchmark_start_price', 'peak_equity', 'realized_fees_quote', 'started_at_ms',
        'valuation_price', 'valuation_source', 'valuation_at_ms',
    ];

    protected function casts(): array
    {
        return ['started_at_ms' => 'integer', 'valuation_at_ms' => 'integer'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MarketSubscription::class, 'market_subscription_id', 'market_subscription_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ClientPaperEvent::class, 'client_paper_account_id', 'client_paper_account_id');
    }
}
