<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HumanTrainingReview extends Model
{
    use HasFactory, HasUniqueIdentifier;

    protected $primaryKey = 'review_id';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['confidence' => 'integer', 'shown_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $review): void {
            if ($review->getOriginal('submitted_at') !== null) {
                throw new LogicException('Submitted human reviews are immutable.');
            }
        });
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(HumanTrainingSnapshot::class, 'snapshot_id', 'snapshot_id');
    }
}
