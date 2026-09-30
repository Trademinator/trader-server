<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HumanCandleLabel extends Model
{
    use HasFactory, HasUniqueIdentifier;

    protected $primaryKey = 'candle_label_id';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(HumanTrainingSnapshot::class, 'snapshot_id', 'snapshot_id');
    }
}
