<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketEventCandidate extends Model
{
    use HasUniqueIdentifier;

    protected $primaryKey = 'market_event_candidate_id';

    protected $fillable = [
        'source_hash',
        'provider',
        'source_url',
        'source_domain',
        'source_title',
        'source_seen_at',
        'source_language',
        'source_country',
        'context_snippet',
        'event_type',
        'matched_symbols',
        'machine_confidence',
        'machine_evidence',
    ];

    protected function casts(): array
    {
        return [
            'source_seen_at' => 'datetime',
            'matched_symbols' => 'array',
            'machine_confidence' => 'decimal:4',
            'machine_evidence' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }
}
