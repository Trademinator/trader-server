<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\MarketChart;
use App\Domain\MarketSuggestions\MarketDiscovery;
use App\Models\Market;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class DashboardData
{
    public function __construct(private MarketChart $charts, private IntelligenceReadiness $readiness,
        private CandleTimeframe $timeframe, private MarketDiscovery $discovery) {}

    public function subscriptions(User $user): Builder
    {
        return MarketSubscription::query()->where('user_id', $user->user_id)->where('active', true)
            ->with('market.exchange', 'market.feed', 'market.latestSignal');
    }

    public function overview(User $user, ?string $selectedId, int $since): array
    {
        $subscriptions = $this->subscriptions($user)->orderBy('created_at')->orderBy('market_subscription_id')
            ->paginate(max(1, min(24, (int) config('dashboard.page_size'))))->withQueryString();
        $selected = $selectedId === null ? $subscriptions->first() : $this->subscriptions($user)->findOrFail($selectedId);
        $keys = $subscriptions->getCollection()->map(fn ($item) => $this->key($item->market))->all();
        if ($selected !== null) {
            $keys[] = $this->key($selected->market);
        }
        $reports = DB::table('intelligence_heads as heads')->join('intelligence_models as models', 'models.model_id', '=', 'heads.model_id')
            ->whereIn('heads.market_key', array_unique($keys))->get(['heads.market_key', 'models.report'])
            ->mapWithKeys(fn ($row) => [$row->market_key => json_decode($row->report, true, flags: JSON_THROW_ON_ERROR)]);
        $cards = $subscriptions->getCollection()->map(function (MarketSubscription $item) use ($reports): array {
            $chart = $this->charts->data($item->market, 48);
            $report = $reports->get($this->key($item->market));
            $ready = $this->ready($report);
            $signal = $item->market->latestSignal;
            $fresh = $this->fresh($item->market, $signal, $report);
            $attention = $chart['stale'] || $chart['gaps'] > 0 || $chart['invalid_candles'] > 0
                || $item->market->feed?->last_error !== null || ! in_array($item->market->feed?->status, ['active', 'pending'], true);

            return ['subscription' => $item, 'chart' => $chart, 'report' => $report, 'ready' => $ready,
                'signal' => $signal, 'signal_fresh' => $fresh, 'attention' => $attention,
                'sparkline' => $this->sparkline($chart), 'label' => $fresh && $signal !== null
                    ? SignalJournal::label($signal->action, $signal->reason) : 'Waiting for evidence'];
        });
        $changes = MarketSignal::query()->where('is_change', true)->where('recorded_at_ms', '>', $since)
            ->where('recorded_at_ms', '<=', now()->getTimestampMs())
            ->whereHas('market.subscriptions', fn ($query) => $query->where('user_id', $user->user_id)->where('active', true));
        $changeCount = (clone $changes)->count();
        $timeline = $changes->with('market.exchange')->orderByDesc('recorded_at_ms')->orderByDesc('market_signal_id')->limit(20)->get();
        $details = null;
        if ($selected !== null) {
            $market = $selected->market;
            $report = $reports->get($this->key($market));
            $signal = $market->latestSignal;
            $fresh = $this->fresh($market, $signal, $report);
            $current = $fresh ? $signal->payload : WeightedKnn::abstain($market->feed?->selected_period === null ? 'period_pending'
                : ($report === null ? 'no_model' : ($this->ready($report) ? 'awaiting_recording' : ($report['reason'] ?? 'model_unavailable'))));
            $cacheKey = 'trademinator:dashboard-readiness:'.hash('sha256', json_encode([
                $this->key($market), $report['model_id'] ?? null, $current['reason'], $market->feed?->updated_at?->getTimestamp(),
            ], JSON_THROW_ON_ERROR));
            $progress = Cache::remember($cacheKey, 60, fn () => $this->readiness->describe($market->exchange->class,
                $market->symbol, $market->feed?->selected_period, $market->feed, $report, $current));
            $details = ['subscription' => $selected, 'report' => $report, 'signal' => $signal, 'signal_fresh' => $fresh,
                'progress' => $progress, 'chart' => $this->charts->data($market),
                'history' => MarketSignal::query()->where('market_id', $market->getKey())
                    ->orderByDesc('recorded_at_ms')->orderByDesc('market_signal_id')->limit(20)->get()];
        }
        $overlap = $cards->groupBy(fn (array $card): string => explode('/', $card['subscription']->market->symbol)[0])
            ->filter(fn ($group): bool => $group->count() > 1)->map->count();

        return compact('subscriptions', 'cards', 'details', 'timeline', 'changeCount', 'overlap', 'since')
            + ['conditions' => $this->discovery->snapshot()];
    }

    private function key(Market $market): string
    {
        return ModelStore::marketKey($market->exchange->class, $market->symbol, $market->feed?->selected_period ?? '');
    }

    private function ready(?array $report): bool
    {
        return $report !== null && ($report['status'] ?? null) === 'ready'
            && ($report['validation_version'] ?? null) === IntelligenceTrainer::VERSION
            && ($report['trained_as_of_ms'] ?? 0) >= now()->getTimestampMs() - config('intelligence.max_model_age_days') * 86400000;
    }

    private function fresh(Market $market, ?MarketSignal $signal, ?array $report): bool
    {
        if ($signal === null || $signal->period !== $market->feed?->selected_period
            || $signal->model_id !== ($report['model_id'] ?? null)) {
            return false;
        }
        if ($signal->reason !== 'supported') {
            return true;
        }
        if (! $this->ready($report) || $signal->decision_at_ms === null) {
            return false;
        }
        $expires = $signal->decision_at_ms;
        for ($i = 0; $i < config('intelligence.max_signal_age_periods'); $i++) {
            $expires = $this->timeframe->next($expires, $signal->period);
        }

        return now()->getTimestampMs() < $expires;
    }

    private function sparkline(array $chart): ?string
    {
        $prices = array_column($chart['series'], 'close');
        if (count($prices) < 2 || $chart['gaps'] > 0 || $chart['invalid_candles'] > 0) {
            return null;
        }
        $minimum = min($prices);
        $span = max($prices) - $minimum;
        $points = [];
        foreach ($prices as $i => $price) {
            $points[] = round($i * 200 / (count($prices) - 1), 2).','.round($span > 0 ? 44 - 40 * ($price - $minimum) / $span : 24, 2);
        }

        return implode(' ', $points);
    }
}
