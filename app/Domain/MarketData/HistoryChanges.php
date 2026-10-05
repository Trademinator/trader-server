<?php

namespace App\Domain\MarketData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** A transactionally versioned record of actual source changes, separate from snapshots. */
final class HistoryChanges
{
    public function record(string $marketId, string $period, ?int $fromMs = null, ?int $toMs = null,
        string $source = 'gap_repair', bool $immediate = false): int
    {
        if (($fromMs === null) !== ($toMs === null) || ($fromMs !== null && ($fromMs < 0 || $toMs < $fromMs))) {
            throw new InvalidArgumentException('History change bounds must be ordered or both unknown.');
        }

        return DB::transaction(function () use ($marketId, $period, $fromMs, $toMs, $source, $immediate): int {
            DB::table('market_history_backfills')->insertOrIgnore([
                'history_id' => (string) Str::uuid7(), 'market_id' => $marketId, 'period' => $period,
                'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $query = DB::table('market_history_backfills')->where('market_id', $marketId)->where('period', $period);
            $state = (clone $query)->lockForUpdate()->first();
            $revision = (int) $state->history_revision + 1;
            DB::table('market_history_changes')->insert([
                'change_id' => (string) Str::uuid7(), 'history_id' => $state->history_id,
                'revision' => $revision, 'from_ms' => $fromMs, 'to_ms' => $toMs,
                'source' => $source, 'created_at' => now(),
            ]);
            $updates = ['history_revision' => $revision, 'updated_at' => now()];
            // A fixed debounce from the first change coalesces pages without letting
            // a continually busy exchange postpone rebuilding indefinitely.
            if ($immediate || ((int) $state->history_revision === (int) $state->trained_revision && $state->build_stage === null)) {
                $updates['build_next_attempt_at'] = $immediate ? now() : now()->addSeconds(30);
            }
            $query->update($updates);

            return $revision;
        });
    }

    public function replayStart(object $state, int $targetRevision): ?int
    {
        $changes = DB::table('market_history_changes')->where('history_id', $state->history_id)
            ->where('revision', '>', $state->trained_revision)->where('revision', '<=', $targetRevision)
            ->orderBy('revision')->cursor();

        return HistoryReplayWindow::earliest((int) $state->trained_revision, $targetRevision, $changes);
    }
}
