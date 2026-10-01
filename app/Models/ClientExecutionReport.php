<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ClientExecutionReport extends Model
{
    use HasUniqueIdentifier;

    protected $primaryKey = 'client_execution_report_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'market_subscription_id', 'market_signal_id', 'idempotency_key', 'request_hash',
        'event', 'side', 'reason', 'protective', 'quantity', 'price', 'fee', 'fee_currency',
        'exchange_order_id', 'exchange_trade_id', 'occurred_at_ms', 'recorded_at_ms',
    ];

    protected function casts(): array
    {
        return ['protective' => 'boolean', 'occurred_at_ms' => 'integer', 'recorded_at_ms' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Client execution reports are immutable. Append another report instead.');
        });
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(MarketSignal::class, 'market_signal_id', 'market_signal_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MarketSubscription::class, 'market_subscription_id', 'market_subscription_id');
    }
}
