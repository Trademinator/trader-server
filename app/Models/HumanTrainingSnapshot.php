<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class HumanTrainingSnapshot extends Model
{
    use HasFactory, HasUniqueIdentifier;

    protected $primaryKey = 'snapshot_id';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'decision_at_ms' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Human training snapshots are immutable.');
        });
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(HumanTrainingReview::class, 'snapshot_id', 'snapshot_id');
    }

    public static function digest(array $payload): string
    {
        $canonicalize = function (array $value) use (&$canonicalize): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$child) {
                if (is_array($child)) {
                    $child = $canonicalize($child);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($canonicalize($payload), JSON_THROW_ON_ERROR));
    }

    public function verifiedPayload(): array
    {
        if (! hash_equals($this->sha256, self::digest($this->payload))) {
            throw new LogicException('Human training snapshot checksum mismatch.');
        }

        return $this->payload;
    }
}
