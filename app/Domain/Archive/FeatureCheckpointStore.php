<?php

namespace App\Domain\Archive;

use App\Domain\Features\FeatureEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FeatureCheckpointStore
{
    public function save(string $exchange, string $symbol, string $period, int $throughMs, array $state): void
    {
        $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        DB::table('feature_checkpoints')->updateOrInsert([
            'exchange' => $exchange, 'symbol' => $symbol, 'period' => $period,
            'feature_version' => FeatureEngine::VERSION, 'through_ms' => $throughMs,
        ], [
            'checkpoint_id' => (string) Str::uuid7(),
            'checkpoint_version' => (int) config('archive.feature_checkpoint_version'),
            'state' => $encoded,
            'sha256' => hash('sha256', $encoded),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function before(string $exchange, string $symbol, string $period, int $beforeMs): ?array
    {
        $row = DB::table('feature_checkpoints')->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
            ->where('feature_version', FeatureEngine::VERSION)->where('checkpoint_version', (int) config('archive.feature_checkpoint_version'))
            ->where('through_ms', '<', $beforeMs)->orderByDesc('through_ms')->first();
        if ($row === null) {
            return null;
        }
        $json = is_string($row->state) ? $row->state : json_encode($row->state, JSON_THROW_ON_ERROR);
        if (! hash_equals((string) $row->sha256, hash('sha256', $json))) {
            return null;
        }
        $state = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($state)) {
            return null;
        }
        if (($state['feature_version'] ?? null) !== FeatureEngine::VERSION || ($state['through_ms'] ?? null) !== (int) $row->through_ms) {
            return null;
        }

        return $state;
    }
}
