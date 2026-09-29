<?php

namespace App\Domain\MarketSuggestions;

use App\Domain\MarketData\MarketCatalog;
use App\Models\CoinGeckoMarketMapping;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PairSuggestions
{
    public function __construct(private MarketCatalog $catalog, private RegionalAccess $access, private CandleEvidence $evidence, private MarketDiscovery $discovery) {}

    public function suggest(User $user, array $answers, Exchange $exchange, ?string $reviewSymbol = null, bool $discoveryOnly = false): array
    {
        $access = $this->access->check($answers);
        $result = ['items' => [], 'excluded' => [], 'notes' => [], 'access' => $access, 'considered' => 0,
            'catalogue_count' => 0, 'generated_at' => now()->utc()->format('Y-m-d H:i').' UTC'];
        if ($access['blocked']) {
            $result['notes'][] = $access['message'];

            return $result;
        }
        if ($answers['goal'] !== 'learn' && ($answers['loss_impact'] === 'yes' || $answers['money_needed'] === 'soon')) {
            $result['notes'][] = 'No trading shortlist: you may need this money soon or a loss could affect essential expenses. You can change your goal to learning and follow markets without allocating money.';

            return $result;
        }
        if ($answers['goal'] !== 'learn' && $answers['horizon'] === 'hours' && $answers['monitoring'] !== 'frequent') {
            $result['notes'][] = 'No trading shortlist: an hours-long holding horizon conflicts with your available monitoring time. Review those answers or choose learning.';

            return $result;
        }
        $options = $this->catalog->forExchange($exchange);
        $discovery = $this->discovery->snapshot();
        $mappings = CoinGeckoMarketMapping::query()->with('market:market_id,symbol')
            ->whereHas('market', fn ($query) => $query->where('exchange_id', $exchange->exchange_id))
            ->get()->keyBy(fn ($mapping) => $mapping->market->symbol);
        $hidden = $discoveryOnly ? DB::table('market_suggestion_dismissals')->where('user_id', $user->user_id)
            ->where('exchange', $exchange->class)->where('dismissed_at', '>=', now()->subDays(30))->pluck('symbol')->all() : [];
        if ($discoveryOnly) {
            $hidden = array_merge($hidden, Market::query()->where('exchange_id', $exchange->exchange_id)
                ->whereHas('subscriptions', fn ($query) => $query->where('user_id', $user->user_id)->where('active', true))->pluck('symbol')->all());
        }
        $result['catalogue_count'] = count($options['symbols']);
        $holdings = array_column($answers['holdings'], 'band', 'asset');
        $held = array_keys($holdings);
        $excluded = preg_split('/[\s,]+/', (string) $answers['excluded_assets'], -1, PREG_SPLIT_NO_EMPTY);
        if ($answers['exclude_stablecoins']) {
            $excluded = array_unique(array_merge($excluded, config('market_suggestions.stablecoins', [])));
        }
        $target = $answers['goal'] === 'accumulate' ? $answers['target_asset'] : $answers['reference_currency'];
        $fundable = $held;
        if ($answers['conversions'] === 'one') {
            foreach ($options['symbols'] as $option) {
                [$base, $quote] = explode('/', $option['value']);
                if (array_intersect([$base, $quote], $excluded) || ! $this->symbolAllowed($option['value'], $access)) {
                    continue;
                }
                if (in_array($base, $held, true)) {
                    $fundable[] = $quote;
                }
                if (in_array($quote, $held, true)) {
                    $fundable[] = $base;
                }
            }
        }
        $fundable = array_fill_keys($fundable, true);
        $candidates = [];
        foreach ($options['symbols'] as $option) {
            $symbol = $option['value'];
            if (in_array($symbol, $hidden, true)) {
                continue;
            }
            [$base, $quote] = explode('/', $symbol);
            $reason = null;
            if (array_intersect([$base, $quote], $excluded)) {
                $reason = 'Contains an asset you excluded';
            } elseif (! $this->symbolAllowed($symbol, $access)) {
                $reason = 'Excluded by the regional pair review';
            } elseif (empty($option['tick_size'])) {
                $reason = 'Fixed price increment unavailable';
            } elseif (($option['active'] ?? null) === false) {
                $reason = 'Market inactive';
            } elseif ($answers['goal'] === 'accumulate' && ! in_array($target, [$base, $quote], true)) {
                $reason = 'Does not include the asset you want to accumulate';
            } elseif ($held !== [] && ! isset($fundable[$base]) && ! isset($fundable[$quote])) {
                $reason = 'Cannot be funded within your conversion preference';
            }
            if ($reason !== null) {
                $this->exclude($result, $reason);

                continue;
            }
            $direct = (bool) array_intersect([$base, $quote], $held);
            $breakdown = [
                ['label' => 'Direct funding', 'points' => $direct ? 40 : 0, 'maximum' => 40,
                    'rule' => '40 points when you hold either asset on this exchange.'],
                ['label' => 'Quote asset held', 'points' => in_array($quote, $held, true) ? 20 : 0, 'maximum' => 20,
                    'rule' => '20 points when you hold '.$quote.', which can fund a spot purchase of '.$base.'.'],
                ['label' => 'Base asset held', 'points' => in_array($base, $held, true) ? 10 : 0, 'maximum' => 10,
                    'rule' => '10 points when you hold '.$base.', which can fund a spot sale for '.$quote.'.'],
                ['label' => 'Goal currency match', 'points' => $quote === $target ? 30 : ($base === $target ? 20 : 0), 'maximum' => 30,
                    'rule' => '30 points if the quote is '.$target.'; otherwise 20 if the base is '.$target.'; otherwise 0.'],
            ];
            $activity = $discovery['coins'][$base] ?? null;
            $mapping = $mappings->get($symbol);
            if ($mapping !== null && ($mapping->status !== 'resolved' || $mapping->coin_id !== ($activity['coin_id'] ?? null))) {
                $activity = null;
            }
            $candidates[] = $option + ['base' => $base, 'quote' => $quote, 'score' => array_sum(array_column($breakdown, 'points')),
                'score_breakdown' => $breakdown, 'direct' => $direct, 'activity' => $activity];
        }
        usort($candidates, fn ($a, $b) => ($b['score'] <=> $a['score'])
            ?: (($b['activity']['activity_ratio'] ?? -1) <=> ($a['activity']['activity_ratio'] ?? -1)) ?: strcmp($a['value'], $b['value']));
        $limit = max(1, min(50, (int) config('market_suggestions.candidate_limit', 24)));
        if (count($candidates) > $limit) {
            $result['notes'][] = 'Detailed history checks cover the '.$limit.' closest funding and goal matches. This is a bounded shortlist, not a scan of every market’s profitability.';
        }
        $candidates = array_slice($candidates, 0, $limit);
        $markets = Market::query()->with('feed')->where('exchange_id', $exchange->exchange_id)
            ->whereIn('symbol', array_column($candidates, 'value'))->get()->keyBy('symbol');
        $subscribed = MarketSubscription::query()->where('user_id', $user->user_id)->where('active', true)
            ->whereIn('market_id', $markets->pluck('market_id'))->pluck('market_id')->all();
        foreach ($candidates as $candidate) {
            $result['considered']++;
            $symbol = $candidate['value'];
            $base = $candidate['base'];
            $quote = $candidate['quote'];
            $market = $markets->get($symbol);
            $activity = $candidate['activity'];
            $evidence = $this->evidence->inspect($exchange->class, $symbol, $market?->feed?->selected_period,
                $answers['horizon'], $target === $base, $symbol === $reviewSymbol);
            $riskLimit = (float) config('market_suggestions.risk_limits.'.$answers['risk'], 0.10);
            if ($answers['experience'] === 'new') {
                $riskLimit = min($riskLimit, 0.10);
            }
            if ($evidence['known'] && max($evidence['drawdown'], $evidence['largest_move']) > $riskLimit) {
                $this->exclude($result, 'Observed price swings exceed the historical screen for your preferences');

                continue;
            }
            if ($evidence['known'] && $evidence['zero_volume_fraction'] > 0.1) {
                $this->exclude($result, 'Too many stored candles have no trading volume');

                continue;
            }
            $reasons = [];
            $cautions = [];
            if (in_array($base, $held, true) && in_array($quote, $held, true)) {
                $reasons[] = 'You hold both '.$base.' and '.$quote.' on this exchange.';
            } elseif (in_array($quote, $held, true)) {
                $reasons[] = 'Your '.$quote.' balance can fund the buy side of this spot pair.';
            } elseif (in_array($base, $held, true)) {
                $reasons[] = 'You already hold '.$base.'; a spot sale would use existing units. No short selling is assumed.';
            } elseif ($held !== []) {
                $reasons[] = 'A listed pair connects your holdings to this pair within one conversion on this exchange.';
                $cautions[] = 'Conversion fees, minimums and available amounts have not been checked.';
            } else {
                $cautions[] = 'Funding is unknown because no holdings were provided.';
            }
            $reasons[] = $quote === $target ? 'Prices are quoted in '.$target.', matching your goal.'
                : ($base === $target ? 'This pair includes '.$target.', the asset you want to measure or accumulate.'
                    : 'Returns in '.$target.' would require a separate currency conversion; none is estimated here.');
            $allocationMax = Questionnaire::BANDS[$answers['allocation']][1];
            if ($quote === $answers['reference_currency'] && $candidate['direct']) {
                // Holding bands are values in the reference currency, including base holdings.
                // Either side can fund a spot trade; an unknown/open band cannot establish a cap.
                $caps = [];
                foreach ([$base, $quote] as $asset) {
                    if (isset($holdings[$asset])) {
                        $caps[] = Questionnaire::BANDS[$holdings[$asset]][1];
                    }
                }
                if ($caps !== [] && ! in_array(null, $caps, true)) {
                    $holdingMax = max($caps);
                    $allocationMax = $allocationMax === null ? $holdingMax : min($allocationMax, $holdingMax);
                }
            }
            $minCost = $candidate['min_cost'] ?? null;
            if (isset($candidate['min_amount'], $evidence['last_close'])) {
                $minCost = max($minCost ?? 0, $candidate['min_amount'] * $evidence['last_close']);
            }
            if ($quote === $answers['reference_currency'] && $allocationMax !== null && $minCost !== null && $minCost > $allocationMax) {
                $this->exclude($result, 'Minimum order exceeds your stated allocation or quote balance range');

                continue;
            }
            $cautions[] = $minCost !== null ? 'Indicative minimum order: '.rtrim(rtrim(sprintf('%.8F', $minCost), '0'), '.').' '.$quote.'. Exact balance and exchange limits still need checking.'
                : 'Minimum order value is unknown.';
            if ($quote !== $answers['reference_currency']) {
                $cautions[] = 'Your allocation range is in '.$answers['reference_currency'].'; affordability in '.$quote.' has not been verified.';
            }
            $fee = $candidate['taker_fee'] ?? null;
            $cautions[] = $fee === null ? 'Trading fees, spread and slippage are unknown.'
                : 'Published taker fee: '.number_format($fee * 100, 3).'% per side (about '.number_format($fee * 200, 3).'% for two trades). Your fee tier, spread and slippage may differ.';
            if (($candidate['active'] ?? null) !== true) {
                $cautions[] = 'The exchange did not explicitly confirm that this market is active.';
            }
            if (! $evidence['known']) {
                $cautions[] = $evidence['message'];
            }
            if ($answers['experience'] === 'new') {
                $cautions[] = 'Beginner screening uses a 10% historical swing threshold. It does not cap future losses.';
            }
            $explorationReasons = [];
            foreach ([
                [! $access['verified'], 'No current independent regional access review is available.'],
                [! $answers['access_confirmed'], 'You have not confirmed access to this exchange.'],
                [! $evidence['known'], $evidence['message']],
                [! $candidate['direct'], 'Direct funding from a stated holding is not established.'],
                [($candidate['active'] ?? null) !== true, 'The exchange has not explicitly confirmed this market is active.'],
                [$answers['goal'] === 'learn', 'Your goal is learning and following markets.'],
                [$answers['loss_impact'] !== 'no', 'You have not ruled out an impact on essential expenses.'],
                [$answers['risk'] === 'unsure', 'Your risk preference is not yet specified.'],
                [$answers['horizon'] === 'unsure', 'Your holding horizon is not yet specified.'],
                [$answers['money_needed'] === 'unsure', 'When you might need the money is unknown.'],
                [$answers['allocation'] === 'unsure', 'Your intended allocation range is unknown.'],
            ] as [$applies, $explanation]) {
                if ($applies) {
                    $explorationReasons[] = $explanation;
                }
            }
            $explore = $explorationReasons !== [];
            $result['items'][] = ['symbol' => $symbol, 'base' => $base, 'quote' => $quote, 'reasons' => $reasons,
                'activity' => $activity, 'activity_observed_at_ms' => $activity === null ? null : $discovery['observed_at_ms'],
                'cautions' => $cautions, 'evidence' => $evidence, 'explore' => $explore,
                'score' => $candidate['score'], 'risk_currency' => $target === $base ? $base : $quote,
                'score_breakdown' => $candidate['score_breakdown'], 'exploration_reasons' => $explorationReasons,
                'risk_limit' => $riskLimit, 'target' => $target, 'direct' => $candidate['direct'],
                'market_details' => ['tick_size' => $candidate['tick_size'], 'active' => $candidate['active'] ?? null,
                    'min_cost' => $candidate['min_cost'] ?? null, 'min_amount' => $candidate['min_amount'] ?? null,
                    'indicative_min_cost' => $minCost, 'taker_fee' => $fee, 'allocation_cap' => $allocationMax,
                    'affordability_checked' => $quote === $answers['reference_currency'] && $allocationMax !== null && $minCost !== null],
                'subscribed' => $market !== null && in_array($market->market_id, $subscribed, true)];
        }
        usort($result['items'], fn ($a, $b) => ($a['explore'] <=> $b['explore']) ?: ($b['score'] <=> $a['score'])
            ?: (($b['activity']['activity_ratio'] ?? -1) <=> ($a['activity']['activity_ratio'] ?? -1)) ?: strcmp($a['symbol'], $b['symbol']));
        // Cap duplicate base exposure. This is not a claim of measured diversification.
        $seen = [];
        $result['items'] = array_values(array_filter($result['items'], function ($item) use (&$seen) {
            if (isset($seen[$item['base']])) {
                return false;
            }
            $seen[$item['base']] = true;

            return true;
        }));
        $result['items'] = array_slice($result['items'], 0, max(1, min(5, (int) config('market_suggestions.shortlist_limit', 5))));
        $result['notes'][] = 'The list keeps one pair per base asset to avoid repetition. Different assets may still move together; portfolio correlations are not measured.';
        if ($answers['risk'] === 'unsure' || $answers['loss_impact'] === 'unsure' || $answers['allocation'] === 'unsure'
            || $answers['money_needed'] === 'unsure' || $answers['horizon'] === 'unsure') {
            $result['notes'][] = 'Some suitability answers are unknown. Results remain exploratory until you refine them.';
        }

        return $result;
    }

    private function symbolAllowed(string $symbol, array $access): bool
    {
        return ! in_array($symbol, $access['excluded_symbols'], true)
            && ($access['allowed_symbols'] === null || in_array($symbol, $access['allowed_symbols'], true));
    }

    private function exclude(array &$result, string $reason): void
    {
        $result['excluded'][$reason] = ($result['excluded'][$reason] ?? 0) + 1;
    }
}
