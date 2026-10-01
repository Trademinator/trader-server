<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ClientPaperEvent extends Model
{
    use HasUniqueIdentifier;

    protected $primaryKey = 'client_paper_event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'client_paper_account_id', 'market_signal_id', 'idempotency_key', 'request_hash', 'event', 'reason',
        'side', 'quantity', 'price', 'fee_quote', 'occurred_at_ms', 'recorded_at_ms', 'result',
    ];

    protected function casts(): array
    {
        return ['occurred_at_ms' => 'integer', 'recorded_at_ms' => 'integer', 'result' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Paper events are immutable.');
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ClientPaperAccount::class, 'client_paper_account_id', 'client_paper_account_id');
    }
}
