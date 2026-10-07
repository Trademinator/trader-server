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
            && ($report['trained_as_of_ms'] ?? 0) >= KnowledgeWindow::fromMs(now()->getTimestampMs());
    }

    /** @return array<string, array{ready: bool, reason: string}> */
    public static function knnReadiness(?array $report): array
    {
        $unavailable = match (true) {
            $report === null => 'no_model',
            ($report['validation_version'] ?? null) !== IntelligenceTrainer::VERSION => 'model_version_mismatch',
            ($report['trained_as_of_ms'] ?? 0) < KnowledgeWindow::fromMs(now()->getTimestampMs()) => 'stale_model',
            default => null,
        };
        $automatic = $report['automatic'] ?? [];
        $human = $report['candle_guidance'] ?? [];
        $reasons = [
            'automatic' => match (true) {
                ($automatic['status'] ?? null) === 'ready' => 'validated',
                in_array($automatic['reason'] ?? null, [null, 'validated'], true) => 'automatic_model_unavailable',
                default => $automatic['reason'],
            },
            'human_candle' => match (true) {
                ! OptionalGuidance::enabled('candle') => 'candle_training_disabled',
                ($human['version'] ?? null) !== HumanCandleKnn::VERSION => 'candle_model_version_mismatch',
                ($human['status'] ?? null) === 'validated' && ($human['influence'] ?? false) => 'validated',
                ($human['status'] ?? null) === 'validated' => 'candle_model_unavailable',
                default => $human['status'] ?? 'candle_model_unavailable',
            },
        ];
        $readiness = [];
        foreach ($reasons as $name => $reason) {
            $reason = $unavailable ?? $reason;
            if ($reason === 'validated' && ($report['ensemble']['weights'][$name] ?? 0) <= 0) {
                $reason = 'zero_scoring_weight';
            }
            if ($reason === 'validated' && ! self::isReadyReport($report)) {
                $reason = 'model_unavailable';
            }
            $readiness[$name] = ['ready' => $reason === 'validated', 'reason' => $reason];
        }

        return $readiness;
    }

    public function path(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('Model ID must be a UUID.');
        }

        return rtrim(config('intelligence.path'), '/').'/'.$id.'.model';
    }

    public function knowledgePath(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('Model ID must be a UUID.');
        }

        return rtrim(config('intelligence.path'), '/').'/'.$id.'.knowledge.jsonl';
    }

    public function save(array $artifact): array
    {
        $persistenceStarted = hrtime(true);
        $buildStarted = $artifact['build_performance']['started_monotonic_ns'] ?? null;
        unset($artifact['build_performance']['started_monotonic_ns']);
        $id = (string) Str::uuid7();
        $artifact['model_id'] = $id;
        $artifact['created_at'] = now()->toIso8601String();
        $artifact['format_version'] = 'm4-intelligence-v2';
        $path = $this->path($id);
        $knowledgePath = $this->knowledgePath($id);
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true)) {
            throw new RuntimeException('Cannot create private model directory.');
        }

        $knowledge = $artifact['knowledge'] ?? [];
        $knowledgeHash = hash_init('sha256');
        $knowledgeHandle = fopen($knowledgePath.'.tmp', 'wb');
        if ($knowledgeHandle === false) {
            throw new RuntimeException('Cannot create private model knowledge file.');
        }
        try {
            foreach ($knowledge as $row) {
                $line = json_encode($row, JSON_THROW_ON_ERROR)."\n";
                if (fwrite($knowledgeHandle, $line) !== strlen($line)) {
                    throw new RuntimeException('Cannot write private model knowledge file.');
                }
                hash_update($knowledgeHash, $line);
            }
        } finally {
            fclose($knowledgeHandle);
        }
        $artifact['knowledge_sha256'] = hash_final($knowledgeHash);
        unset($artifact['knowledge']);
        $bytes = serialize($artifact);
        $report = $artifact;
        unset($report['patterns'], $report['lead_lag']['models'], $report['knowledge_sha256']);
        unset($report['human_guidance']['estimator'], $report['candle_guidance']['estimator'], $report['candle_guidance']['knowledge']);
        $report['patterns'] = $artifact['patterns']['report'];
        try {
            if (! chmod($knowledgePath.'.tmp', 0600) || ! rename($knowledgePath.'.tmp', $knowledgePath)
                || file_put_contents($path.'.tmp', $bytes, LOCK_EX) !== strlen($bytes)
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
            foreach ([$path.'.tmp', $path, $knowledgePath.'.tmp', $knowledgePath] as $file) {
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
        $artifact = $this->loadArtifact($id);
        if (($artifact['format_version'] ?? null) === 'm4-intelligence-v2') {
            $artifact['knowledge'] = iterator_to_array($this->knowledge($artifact), false);
        }

        return $artifact;
    }

    public function current(string $exchange, string $symbol, string $period): ?array
    {
        $id = DB::table('intelligence_heads')->where('market_key', self::marketKey($exchange, $symbol, $period))->value('model_id');

        return $id === null ? null : $this->load($id);
    }

    public function currentForPrediction(string $exchange, string $symbol, string $period): ?array
    {
        $id = DB::table('intelligence_heads')->where('market_key', self::marketKey($exchange, $symbol, $period))->value('model_id');

        return $id === null ? null : $this->loadArtifact($id);
    }

    public function knowledge(array $artifact): iterable
    {
        if (($artifact['format_version'] ?? null) === 'm4-intelligence-v1') {
            yield from $artifact['knowledge'] ?? [];

            return;
        }
        if (($artifact['format_version'] ?? null) !== 'm4-intelligence-v2') {
            throw new RuntimeException('Unsupported intelligence artifact.');
        }

        $path = $this->knowledgePath($artifact['model_id']);
        $handle = is_file($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new RuntimeException('Intelligence model knowledge is missing.');
        }

        $hash = hash_init('sha256');
        try {
            while (($line = fgets($handle)) !== false) {
                hash_update($hash, $line);
                yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        } finally {
            fclose($handle);
        }
        if (! hash_equals((string) ($artifact['knowledge_sha256'] ?? ''), hash_final($hash))) {
            throw new RuntimeException('Intelligence model knowledge checksum does not match.');
        }
    }

    private function loadArtifact(string $id): array
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
        if (! is_array($artifact) || ! in_array($artifact['format_version'] ?? null, ['m4-intelligence-v1', 'm4-intelligence-v2'], true)
            || ($artifact['model_id'] ?? null) !== $id) {
            throw new RuntimeException('Unsupported intelligence artifact.');
        }

        return $artifact;
    }
}
