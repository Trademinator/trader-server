<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MarketSignal extends Model
{
    use HasFactory, HasUniqueIdentifier;

    protected $primaryKey = 'market_signal_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = ['market_id', 'snapshot_key', 'period', 'model_id', 'decision_at_ms',
        'recorded_at_ms', 'is_change', 'action', 'reason', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'decision_at_ms' => 'integer', 'recorded_at_ms' => 'integer', 'is_change' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Recorded signals are immutable. Record a new observation instead.');
        });
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_id', 'market_id');
    }
}
