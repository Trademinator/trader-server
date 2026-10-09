<?php

namespace App\Domain\Intelligence;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Durable, single-source market Action diagnostics for CLI and Web UI.
 * This report is not a model and does not change immutable training datasets.
 */
final class ActionLabelReportStore
{
    public function publish(string $exchange, string $symbol, string $period, array $analysis, ?string $datasetId = null): void
    {
        $summary = ActionLabelAnalysis::publicDiagnostics($analysis);
        $asOfMs = $summary['as_of_ms'] ?? null;
        $counts = $summary['action_counts'] ?? null;
        if (! is_int($asOfMs) || $asOfMs < 1 || ! is_array($counts)
            || array_diff(['buy', 'hold', 'sell'], array_keys($counts)) !== []) {
            throw new InvalidArgumentException('Cannot publish incomplete Action auto-label diagnostics.');
        }

        $key = ['exchange' => $exchange, 'symbol' => $symbol, 'period' => $period];
        $now = now();
        $values = [
            'as_of_ms' => $asOfMs,
            'analysis' => json_encode($summary, JSON_THROW_ON_ERROR),
            'dataset_id' => $datasetId,
            'source' => $datasetId === null ? 'cli' : 'dataset',
            'updated_at' => $now,
        ];

        // Atomic insert plus guarded update. Late workers must not overwrite
        // a newer CLI result, even when they finish after that CLI process.
        DB::table('market_action_label_analyses')->insertOrIgnore([
            ...$key, ...$values, 'created_at' => $now,
        ]);
        DB::table('market_action_label_analyses')->where($key)
            ->where('as_of_ms', '<=', $asOfMs)->update($values);
    }

    /** @return array{analysis: array, as_of_ms: int, computed_at: string, source: string, dataset_id: ?string}|null */
    public function latest(string $exchange, string $symbol, string $period): ?array
    {
        $row = DB::table('market_action_label_analyses')
            ->where(['exchange' => $exchange, 'symbol' => $symbol, 'period' => $period])->first();
        if ($row === null) {
            return null;
        }

        return [
            'analysis' => json_decode($row->analysis, true, flags: JSON_THROW_ON_ERROR),
            'as_of_ms' => (int) $row->as_of_ms,
            'computed_at' => (string) $row->updated_at,
            'source' => (string) $row->source,
            'dataset_id' => $row->dataset_id,
        ];
    }
}
