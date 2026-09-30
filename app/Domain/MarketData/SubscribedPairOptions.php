<?php

namespace App\Domain\MarketData;

use App\Models\MarketSubscription;
use App\Models\User;
use InvalidArgumentException;

final class SubscribedPairOptions
{
    public const DEFAULT_SORT = [
        'exchange' => 'asc',
        'pair' => 'asc',
        'period' => 'asc',
    ];

    private const SORT_FIELDS = ['exchange', 'pair', 'period'];

    /**
     * @return array<int, array{market_id:string,exchange:string,exchange_class:string,pair:string,period:string}>
     */
    public function markets(User $user, bool $allSubscribed = false, array|string|null $sort = null): array
    {
        $records = MarketSubscription::query()
            ->with(['market.exchange', 'market.feed'])
            ->where('active', true)
            ->when(! $allSubscribed, fn ($query) => $query->where('user_id', $user->user_id))
            ->get()
            ->filter(fn (MarketSubscription $subscription): bool => $subscription->market?->exchange !== null)
            ->unique(fn (MarketSubscription $subscription): string => $subscription->market_id)
            ->values()
            ->map(function (MarketSubscription $subscription): array {
                $market = $subscription->market;
                $exchange = $market->exchange;

                return [
                    'market_id' => $market->market_id,
                    'exchange' => $exchange->name ?: $exchange->class,
                    'exchange_class' => $exchange->class,
                    'pair' => $market->symbol,
                    'period' => $market->feed?->selected_period ?? '',
                ];
            })->all();

        return $this->sortRecords($records, $sort);
    }

    /**
     * Scope frozen datasets to subscribed pairs, then apply the same ordering as the pair selector.
     *
     * @param  array<int, array<string, mixed>>  $datasets
     * @return array<int, array<string, mixed>>
     */
    public function datasets(User $user, array $datasets, bool $allSubscribed = false, array|string|null $sort = null): array
    {
        $markets = collect($this->markets($user, $allSubscribed, []))->keyBy(
            fn (array $market): string => $this->marketKey($market['exchange_class'], $market['pair'], $market['period'])
        );
        $records = [];

        foreach ($datasets as $position => $dataset) {
            $exchange = $dataset['exchange'] ?? null;
            $pair = $dataset['symbol'] ?? null;
            $period = $dataset['period'] ?? null;
            if (! is_string($exchange) || ! is_string($pair) || ! is_string($period)) {
                continue;
            }
            $market = $markets->get($this->marketKey($exchange, $pair, $period));
            if (! is_array($market)) {
                continue;
            }
            $records[] = [
                'dataset' => [...$dataset, 'exchange_name' => $market['exchange']],
                'exchange' => $market['exchange'],
                'pair' => $pair,
                'period' => $period,
                '_position' => $position,
            ];
        }

        $criteria = $this->normalizeSort($sort);
        usort($records, fn (array $left, array $right): int => $this->compare($left, $right, $criteria)
            ?: ($left['_position'] <=> $right['_position']));

        return array_values(array_map(fn (array $record): array => $record['dataset'], $records));
    }

    /**
     * @param  array<int, array{market_id:string,exchange:string,exchange_class:string,pair:string,period:string}>  $records
     * @return array<int, array{market_id:string,exchange:string,exchange_class:string,pair:string,period:string}>
     */
    private function sortRecords(array $records, array|string|null $sort): array
    {
        $criteria = $this->normalizeSort($sort);
        usort($records, fn (array $left, array $right): int => $this->compare($left, $right, $criteria)
            ?: strcmp($left['market_id'], $right['market_id']));

        return array_values($records);
    }

    /**
     * @return array<int, array{field:string,direction:string}>
     */
    private function normalizeSort(array|string|null $sort): array
    {
        if ($sort === null || $sort === '' || $sort === []) {
            $sort = self::DEFAULT_SORT;
        }
        if (is_string($sort)) {
            $sort = array_values(array_filter(array_map('trim', explode(',', $sort)), fn (string $item): bool => $item !== ''));
        }

        $criteria = [];
        $seen = [];
        foreach ($sort as $key => $value) {
            if (is_int($key)) {
                $field = (string) $value;
                $direction = 'asc';
                if (str_starts_with($field, '-')) {
                    $direction = 'desc';
                    $field = substr($field, 1);
                } elseif (str_contains($field, ':')) {
                    [$field, $direction] = array_map('trim', explode(':', $field, 2));
                }
            } else {
                $field = (string) $key;
                $direction = (string) $value;
            }
            $field = strtolower(trim($field));
            $direction = strtolower(trim($direction));
            if (! in_array($field, self::SORT_FIELDS, true)) {
                throw new InvalidArgumentException("Unsupported subscribed-pair sort field [{$field}].");
            }
            if (! in_array($direction, ['asc', 'desc'], true)) {
                throw new InvalidArgumentException("Unsupported subscribed-pair sort direction [{$direction}].");
            }
            if (isset($seen[$field])) {
                throw new InvalidArgumentException("Subscribed-pair sort field [{$field}] was provided more than once.");
            }
            $seen[$field] = true;
            $criteria[] = ['field' => $field, 'direction' => $direction];
        }

        return $criteria;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @param  array<int, array{field:string,direction:string}>  $criteria
     */
    private function compare(array $left, array $right, array $criteria): int
    {
        foreach ($criteria as $criterion) {
            $field = $criterion['field'];
            $comparison = $field === 'period'
                ? $this->comparePeriods((string) ($left[$field] ?? ''), (string) ($right[$field] ?? ''))
                : strnatcasecmp((string) ($left[$field] ?? ''), (string) ($right[$field] ?? ''));
            if ($comparison !== 0) {
                return $criterion['direction'] === 'desc' ? -$comparison : $comparison;
            }
        }

        return 0;
    }

    private function comparePeriods(string $left, string $right): int
    {
        if ($left === $right) {
            return 0;
        }
        if ($left === '') {
            return 1;
        }
        if ($right === '') {
            return -1;
        }
        $leftRank = array_search($left, CandleTimeframe::SUPPORTED, true);
        $rightRank = array_search($right, CandleTimeframe::SUPPORTED, true);
        if ($leftRank !== false && $rightRank !== false) {
            return $leftRank <=> $rightRank;
        }
        if ($leftRank !== false) {
            return -1;
        }
        if ($rightRank !== false) {
            return 1;
        }

        return strnatcasecmp($left, $right);
    }

    private function marketKey(string $exchange, string $pair, string $period): string
    {
        return strtolower($exchange).'|'.$pair.'|'.$period;
    }
}
