<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\SubscribedPairOptions;
use App\Models\HumanTrainingReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Advance a trainer to an unseen Outcome snapshot without returning to market selection. */
final class OutcomeTrainingSession
{
    public const SAME_MARKET = 'same';

    public const LEAST_TRAINED = 'least_trained';

    public function __construct(private HumanTraining $training, private SubscribedPairOptions $pairs) {}

    public function next(User $trainer, HumanTrainingReview $submitted, string $mode): ?HumanTrainingReview
    {
        if (! in_array($mode, [self::SAME_MARKET, self::LEAST_TRAINED], true)) {
            throw new InvalidArgumentException('Unknown Outcome Training continuation.');
        }

        // The submitted, checksum-verified snapshot is authoritative. Never accept
        // an exchange, pair or dataset ID from the form when choosing what follows.
        $current = $submitted->snapshot->verifiedPayload();
        $markets = $this->pairs->markets($trainer, allSubscribed: true);
        $datasets = $this->training->datasets($markets);
        $samePair = static fn (array $dataset): bool => strcasecmp($dataset['exchange'], $current['exchange']) === 0
            && $dataset['symbol'] === $current['symbol'];

        if ($mode === self::SAME_MARKET) {
            $candidates = array_values(array_filter($datasets, $samePair));
            // Keep the current candle period when possible; another available
            // period of this exchange/pair is preferable to ending the session.
            usort($candidates, static fn (array $a, array $b): int =>
                (int) ($b['period'] === $current['period']) <=> (int) ($a['period'] === $current['period']));
        } else {
            $candidates = $this->leastTrainedOtherPairs(array_values(array_filter($datasets,
                static fn (array $dataset): bool => ! $samePair($dataset))));
        }

        foreach ($candidates as $dataset) {
            try {
                // assign() enforces trainer authorization, serializes concurrent
                // assignments and excludes every previously reviewed snapshot.
                return $this->training->assign($trainer, $dataset['dataset_id']);
            } catch (ValidationException $exception) {
                if (! array_key_exists('dataset', $exception->errors())) {
                    throw $exception;
                }
                // Dataset became stale, exhausted or has no intact unseen rows.
                // Continue to the next eligible candidate without losing the save.
            }
        }

        return null;
    }

    /**
     * Rank exchange/pair combinations by submitted Outcome reviews, across all
     * trainers and periods. A random tie-breaker prevents systematic first-pair bias.
     *
     * @param  list<array<string, mixed>>  $datasets
     * @return list<array<string, mixed>>
     */
    private function leastTrainedOtherPairs(array $datasets): array
    {
        $groups = [];
        foreach ($datasets as $dataset) {
            $pairKey = strtolower($dataset['exchange'])."\0".$dataset['symbol'];
            $marketKey = ModelStore::marketKey($dataset['exchange'], $dataset['symbol'], $dataset['period']);
            $groups[$pairKey] ??= ['datasets' => [], 'market_keys' => []];
            $groups[$pairKey]['datasets'][] = $dataset;
            $groups[$pairKey]['market_keys'][$marketKey] = true;
        }
        if ($groups === []) {
            return [];
        }

        $marketKeys = array_values(array_unique(array_merge(...array_map(
            static fn (array $group): array => array_keys($group['market_keys']), array_values($groups)
        ))));
        $counts = DB::table('human_training_reviews as reviews')
            ->join('human_training_snapshots as snapshots', 'snapshots.snapshot_id', '=', 'reviews.snapshot_id')
            ->whereIn('snapshots.market_key', $marketKeys)
            ->whereIn('reviews.label', HumanTraining::acceptedLabels())
            ->whereNotNull('reviews.submitted_at')
            ->select('snapshots.market_key')->selectRaw('COUNT(*) as completed')
            ->groupBy('snapshots.market_key')
            ->pluck('completed', 'snapshots.market_key')->all();

        foreach ($groups as &$group) {
            $group['completed'] = array_sum(array_map(
                static fn (string $key): int => (int) ($counts[$key] ?? 0),
                array_keys($group['market_keys'])
            ));
            $group['random_order'] = random_int(0, PHP_INT_MAX);
            shuffle($group['datasets']);
        }
        unset($group);

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int =>
            ($a['completed'] <=> $b['completed']) ?: ($a['random_order'] <=> $b['random_order']));

        return array_merge(...array_column($groups, 'datasets'));
    }
}
