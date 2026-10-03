<?php

namespace App\Domain\Intelligence;

use App\Domain\Operations\ActionLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ModelStore
{
    public static function marketKey(string $exchange, string $symbol, string $period): string
    {
        return hash('sha256', "$exchange|$symbol|$period");
    }

    public static function isReadyReport(?array $report): bool
    {
        return $report !== null && ($report['status'] ?? null) === 'ready'
            && ($report['validation_version'] ?? null) === IntelligenceTrainer::VERSION
            && ($report['trained_as_of_ms'] ?? 0) >= now()->getTimestampMs() - config('intelligence.max_model_age_days') * 86400000;
    }

    public function path(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('Model ID must be a UUID.');
        }

        return rtrim(config('intelligence.path'), '/').'/'.$id.'.model';
    }

    public function save(array $artifact): array
    {
        $persistenceStarted = hrtime(true);
        $buildStarted = $artifact['build_performance']['started_monotonic_ns'] ?? null;
        unset($artifact['build_performance']['started_monotonic_ns']);
        $id = (string) Str::uuid7();
        $artifact['model_id'] = $id;
        $artifact['created_at'] = now()->toIso8601String();
        $artifact['format_version'] = 'm4-intelligence-v1';
        $path = $this->path($id);
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true)) {
            throw new RuntimeException('Cannot create private model directory.');
        }
        $bytes = serialize($artifact);
        $report = $artifact;
        unset($report['knowledge'], $report['patterns'], $report['lead_lag']['models']);
        unset($report['human_guidance']['estimator'], $report['candle_guidance']['estimator']);
        $report['patterns'] = $artifact['patterns']['report'];
        try {
            if (file_put_contents($path.'.tmp', $bytes, LOCK_EX) !== strlen($bytes)
                || ! chmod($path.'.tmp', 0600) || ! rename($path.'.tmp', $path)) {
                throw new RuntimeException('Cannot publish intelligence model.');
            }
            DB::transaction(function () use ($id, $artifact, $bytes, $report): void {
                $key = self::marketKey($artifact['exchange'], $artifact['symbol'], $artifact['period']);
                DB::table('intelligence_models')->insert([
                    'model_id' => $id, 'dataset_id' => $artifact['dataset_id'], 'market_key' => $key,
                    'status' => $artifact['status'], 'generation_key' => $artifact['generation_key'] ?? null, 'sha256' => hash('sha256', $bytes),
                    'report' => json_encode($report, JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
                $head = DB::table('intelligence_heads')->where('market_key', $key)->first();
                $previous = $head === null ? null : $this->report($head->model_id);
                if ($previous === null || $previous['trained_as_of_ms'] <= $artifact['trained_as_of_ms']) {
                    DB::table('intelligence_heads')->upsert([
                        'market_key' => $key, 'model_id' => $id, 'updated_at' => now(),
                    ], ['market_key'], ['model_id', 'updated_at']);
                }
            });
        } catch (Throwable $error) {
            foreach ([$path.'.tmp', $path] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            throw $error;
        }

        $persistenceMs = (int) round((hrtime(true) - $persistenceStarted) / 1_000_000);
        $report['build_performance']['stages']['persistence_ms'] = $persistenceMs;
        $report['build_performance']['total_ms'] = is_int($buildStarted)
            ? (int) round((hrtime(true) - $buildStarted) / 1_000_000)
            : array_sum($report['build_performance']['stages'] ?? []);
        DB::table('intelligence_models')->where('model_id', $id)->update([
            'report' => json_encode($report, JSON_THROW_ON_ERROR),
        ]);

        $performance = $report['build_performance'] ?? [];
        $stages = $performance['stages'] ?? [];
        app(ActionLog::class)->write('intelligence.build.completed', [
            'model_id' => $id, 'dataset_id' => $artifact['dataset_id'], 'exchange' => $artifact['exchange'],
            'symbol' => $artifact['symbol'], 'period' => $artifact['period'], 'status' => $artifact['status'],
            'reason' => $artifact['reason'] ?? null, 'knowledge_rows' => $artifact['knowledge_rows'] ?? 0,
            'total_ms' => $performance['total_ms'] ?? 0,
            'dataset_ms' => $stages['dataset_ms'] ?? 0, 'patterns_ms' => $stages['patterns_ms'] ?? 0,
            'lead_lag_ms' => $stages['lead_lag_ms'] ?? 0, 'knn_tuning_ms' => $stages['knn_tuning_ms'] ?? 0,
            'holdout_ms' => $stages['holdout_ms'] ?? 0, 'human_guidance_ms' => $stages['human_guidance_ms'] ?? 0,
            'candle_guidance_ms' => $stages['candle_guidance_ms'] ?? 0, 'persistence_ms' => $persistenceMs,
            'feature_replay_ms' => $performance['feature_replay']['duration_ms'] ?? 0,
            'feature_rows' => $performance['feature_replay']['rows_processed'] ?? 0,
            'feature_chunks' => $performance['feature_replay']['chunks'] ?? 0,
            'outcome' => 'completed',
        ]);

        return $report;
    }

    public function generation(string $key): ?array
    {
        $id = DB::table('intelligence_models')->where('generation_key', $key)->value('model_id');

        return $id === null ? null : $this->report($id);
    }

    public function report(string $id): array
    {
        $this->path($id);
        $record = DB::table('intelligence_models')->where('model_id', $id)->first();
        if ($record === null) {
            throw new InvalidArgumentException('Unknown intelligence model.');
        }

        return json_decode($record->report, true, flags: JSON_THROW_ON_ERROR);
    }

    public function load(string $id): array
    {
        $path = $this->path($id);
        $record = DB::table('intelligence_models')->where('model_id', $id)->first();
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($record === null || $bytes === false || ! hash_equals($record->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('Intelligence model is missing or its checksum does not match.');
        }
        // Only our private artifacts, authenticated by the independently stored DB digest, are decoded.
        // Never accept arbitrary uploaded or user-supplied serialized models.
        $artifact = unserialize($bytes);
        if (! is_array($artifact) || ($artifact['format_version'] ?? null) !== 'm4-intelligence-v1'
            || ($artifact['model_id'] ?? null) !== $id) {
            throw new RuntimeException('Unsupported intelligence artifact.');
        }

        return $artifact;
    }

    public function current(string $exchange, string $symbol, string $period): ?array
    {
        $id = DB::table('intelligence_heads')->where('market_key', self::marketKey($exchange, $symbol, $period))->value('model_id');

        return $id === null ? null : $this->load($id);
    }
}
