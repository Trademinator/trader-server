<?php

namespace App\Domain\Intelligence;

use App\Models\HumanTrainingSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/** Append replacements; never rewrite a frozen payload or transfer a judgement. */
final class SnapshotRevisions
{
    /** @return array<int, HumanTrainingSnapshot|null> */
    public function resolve(array $manifest, array $payloads): array
    {
        if (count($payloads) > (int) config('intelligence.max_rows')) {
            throw new InvalidArgumentException('Snapshot revision batch exceeds the dataset bound.');
        }
        $marketKey = ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']);
        $result = [];
        foreach (array_chunk(array_keys($payloads), 250) as $decisions) {
            $existing = HumanTrainingSnapshot::query()->where('market_key', $marketKey)
                ->where('version', HumanTraining::VERSION)->whereIn('decision_at_ms', $decisions)
                ->withCount(['candleLabels', 'reviews' => fn ($query) => $query->whereNotNull('submitted_at')])
                ->orderBy('created_at')->orderBy('snapshot_id')->get()->groupBy('decision_at_ms');
            $inserts = $keys = [];
            foreach ($decisions as $decision) {
                $payload = $payloads[$decision];
                $result[$decision] = null;
                if ($payload === null) {
                    continue;
                }
                $digest = SnapshotInput::digest($payload);
                $profile = SnapshotInput::profile($payload);
                $previous = $previousAny = null;
                foreach ($existing->get($decision, collect()) as $candidate) {
                    $candidatePayload = $candidate->verifiedPayload();
                    $previousAny = $candidate;
                    if (hash_equals($digest, SnapshotInput::digest($candidatePayload))) {
                        // Includes legacy snapshots: unchanged reviewed inputs retain their labels.
                        $result[$decision] = $candidate;
                        break;
                    }
                    if (hash_equals($profile, SnapshotInput::profile($candidatePayload))) {
                        $previous = $candidate;
                    }
                }
                if ($result[$decision] !== null) {
                    continue;
                }
                $previous ??= $previousAny;
                $key = SnapshotInput::key($marketKey, (int) $decision, $payload);
                $keys[$decision] = $key;
                $payload['revision'] = [
                    'input_sha256' => $digest,
                    'previous_snapshot_id' => $previous?->snapshot_id,
                    'requires_review' => $previous !== null && ($previous->candle_labels_count + $previous->reviews_count) > 0,
                ];
                $inserts[] = [
                    'snapshot_id' => (string) Str::uuid7(), 'snapshot_key' => $key, 'market_key' => $marketKey,
                    'dataset_id' => $manifest['dataset_id'], 'decision_at_ms' => $decision,
                    'version' => HumanTraining::VERSION, 'sha256' => HumanTrainingSnapshot::digest($payload),
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now(),
                ];
            }
            foreach (array_chunk($inserts, 25) as $chunk) {
                DB::table('human_training_snapshots')->insertOrIgnore($chunk);
            }
            if ($keys !== []) {
                $created = HumanTrainingSnapshot::query()->whereIn('snapshot_key', array_values($keys))->get()->keyBy('snapshot_key');
                foreach ($keys as $decision => $key) {
                    $snapshot = $created->get($key);
                    if ($snapshot === null || ! hash_equals(SnapshotInput::digest($payloads[$decision]), SnapshotInput::digest($snapshot->verifiedPayload()))) {
                        throw new LogicException('Snapshot revision publication failed its input checksum check.');
                    }
                    $result[$decision] = $snapshot;
                }
            }
        }

        return $result;
    }

    public function historyRevision(array $manifest): int
    {
        return (int) (DB::table('market_history_backfills as history')
            ->join('markets', 'markets.market_id', '=', 'history.market_id')
            ->join('exchanges', 'exchanges.exchange_id', '=', 'markets.exchange_id')
            ->where('exchanges.class', $manifest['exchange'])->where('markets.symbol', $manifest['symbol'])
            ->where('history.period', $manifest['period'])->max('history.history_revision') ?? 0);
    }
}
