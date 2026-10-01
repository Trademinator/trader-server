<?php

namespace App\Domain\Client;

use App\Models\ClientPaperAccount;
use App\Models\ClientPaperEvent;
use App\Models\MarketSubscription;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ClientPaperTrading
{
    private const SCALE = 18;

    public function __construct(private ClientDecisionService $decisions) {}

    public function execute(MarketSubscription $subscription, array $input): array
    {
        return DB::transaction(function () use ($subscription, $input): array {
            $subscription->loadMissing('clientSetting', 'market.latestSignal');
            $setting = $subscription->clientSetting;
            abort_if($setting === null || ! $setting->paper_enabled, 409, 'Paper trading is disabled for this market.');

            $hash = hash('sha256', json_encode($this->canonical($input), JSON_THROW_ON_ERROR));
            $account = ClientPaperAccount::query()->where('user_id', $subscription->user_id)
                ->where('market_subscription_id', $subscription->getKey())->lockForUpdate()->first();

            if ($account !== null) {
                $existing = ClientPaperEvent::query()->where('client_paper_account_id', $account->getKey())
                    ->where('idempotency_key', $input['idempotency_key'])->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->request_hash, $hash)) {
                        throw new ConflictHttpException('This paper idempotency key was already used with different data.');
                    }

                    return $existing->result;
                }
            }

            $bid = $this->d($input['best_bid']);
            $ask = $this->d($input['best_ask']);
            $feeBps = $this->d($input['taker_fee_bps']);
            if ($account === null) {
                $initial = $this->d($setting->paper_initial_quote ?: config('client.default_paper_quote'));
                $feeRate = bcdiv($feeBps, '10000', self::SCALE);
                $benchmarkCost = bcmul($ask, bcadd('1', $feeRate, self::SCALE), self::SCALE);
                $account = ClientPaperAccount::query()->create([
                    'user_id' => $subscription->user_id,
                    'market_subscription_id' => $subscription->getKey(),
                    'quote_balance' => $initial,
                    'base_balance' => '0',
                    'initial_quote_balance' => $initial,
                    'benchmark_base_quantity' => bcdiv($initial, $benchmarkCost, self::SCALE),
                    'benchmark_start_price' => $ask,
                    'peak_equity' => $initial,
                    'realized_fees_quote' => '0',
                    'started_at_ms' => (int) $input['reported_at_ms'],
                ]);
            }

            $positionQuote = bcmul($this->d($account->base_balance), $bid, self::SCALE);
            $state = [...$input,
                'quote_balance' => $account->quote_balance,
                'base_balance' => $account->base_balance,
                'position_quote' => $positionQuote,
                'conflicting_exposure' => false,
            ];
            $decision = $this->decisions->evaluate($subscription, $state, 'paper');
            $event = 'skipped';
            $reason = $decision['reason'];
            $side = null;
            $quantity = null;
            $price = null;
            $fee = '0';

            if ($decision['eligible']) {
                $side = $decision['action'];
                $quantity = $this->d($decision['sizing']['base_amount']);
                $price = $this->d($decision['sizing']['reference_price']);
                $quote = $this->d($decision['sizing']['quote_amount']);
                $feeRate = bcdiv($feeBps, '10000', self::SCALE);
                $fee = bcmul($quote, $feeRate, self::SCALE);

                if ($side === 'buy') {
                    $total = bcadd($quote, $fee, self::SCALE);
                    if (bccomp($this->d($account->quote_balance), $total, self::SCALE) < 0) {
                        $decision = [...$decision, 'eligible' => false, 'reason' => 'paper_balance_changed'];
                    } else {
                        $account->quote_balance = bcsub($this->d($account->quote_balance), $total, self::SCALE);
                        $account->base_balance = bcadd($this->d($account->base_balance), $quantity, self::SCALE);
                        $event = 'executed';
                        $reason = 'paper_fill';
                    }
                } elseif ($side === 'sell' && bccomp($this->d($account->base_balance), $quantity, self::SCALE) >= 0) {
                    $account->base_balance = bcsub($this->d($account->base_balance), $quantity, self::SCALE);
                    $account->quote_balance = bcadd($this->d($account->quote_balance), bcsub($quote, $fee, self::SCALE), self::SCALE);
                    $event = 'executed';
                    $reason = 'paper_fill';
                }
            }

            if ($event === 'executed') {
                $account->realized_fees_quote = bcadd($this->d($account->realized_fees_quote), $fee, self::SCALE);
            }
            $equity = bcadd($this->d($account->quote_balance), bcmul($this->d($account->base_balance), $bid, self::SCALE), self::SCALE);
            if (bccomp($equity, $this->d($account->peak_equity), self::SCALE) > 0) {
                $account->peak_equity = $equity;
            }
            $account->save();

            $summary = $this->summary($account, $bid);
            $result = [
                'api_version' => 1,
                'event' => $event,
                'reason' => $reason,
                'signal_id' => $subscription->market->latestSignal?->getKey(),
                'side' => $event === 'executed' ? $side : null,
                'quantity' => $event === 'executed' ? $quantity : null,
                'price' => $event === 'executed' ? $price : null,
                'fee_quote' => $event === 'executed' ? $fee : '0',
                'decision' => $decision,
                'paper' => $summary,
            ];

            ClientPaperEvent::query()->create([
                'client_paper_account_id' => $account->getKey(),
                'market_signal_id' => $subscription->market->latestSignal?->getKey(),
                'idempotency_key' => $input['idempotency_key'],
                'request_hash' => $hash,
                'event' => $event,
                'reason' => $reason,
                'side' => $event === 'executed' ? $side : null,
                'quantity' => $event === 'executed' ? $quantity : null,
                'price' => $event === 'executed' ? $price : null,
                'fee_quote' => $event === 'executed' ? $fee : null,
                'occurred_at_ms' => (int) $input['reported_at_ms'],
                'recorded_at_ms' => now()->getTimestampMs(),
                'result' => $result,
            ]);

            return $result;
        }, 3);
    }

    public function current(MarketSubscription $subscription, ?string $bid = null): ?array
    {
        $account = ClientPaperAccount::query()->where('user_id', $subscription->user_id)
            ->where('market_subscription_id', $subscription->getKey())->first();
        if ($account === null) {
            return null;
        }

        return $this->summary($account, $bid === null ? $account->benchmark_start_price : $this->d($bid));
    }

    private function summary(ClientPaperAccount $account, string $bid): array
    {
        $equity = bcadd($this->d($account->quote_balance), bcmul($this->d($account->base_balance), $bid, self::SCALE), self::SCALE);
        $initial = $this->d($account->initial_quote_balance);
        $benchmark = bcmul($this->d($account->benchmark_base_quantity), $bid, self::SCALE);
        $peak = $this->d($account->peak_equity);
        $drawdown = bccomp($peak, '0', self::SCALE) > 0
            ? bcmul(bcdiv(bcsub($peak, $equity, self::SCALE), $peak, self::SCALE), '100', 8) : '0';
        $return = bccomp($initial, '0', self::SCALE) > 0
            ? bcmul(bcdiv(bcsub($equity, $initial, self::SCALE), $initial, self::SCALE), '100', 8) : '0';
        $benchmarkReturn = bccomp($initial, '0', self::SCALE) > 0
            ? bcmul(bcdiv(bcsub($benchmark, $initial, self::SCALE), $initial, self::SCALE), '100', 8) : '0';
        $events = $account->events()->count();
        $fills = $account->events()->where('event', 'executed')->count();

        return [
            'account_id' => $account->getKey(),
            'quote_balance' => $account->quote_balance,
            'base_balance' => $account->base_balance,
            'equity_quote' => $equity,
            'initial_quote_balance' => $account->initial_quote_balance,
            'return_pct' => (float) $return,
            'peak_equity_quote' => $account->peak_equity,
            'drawdown_pct' => (float) $drawdown,
            'fees_quote' => $account->realized_fees_quote,
            'benchmark' => [
                'method' => 'passive_buy_and_hold_after_one_entry_fee',
                'start_price' => $account->benchmark_start_price,
                'equity_quote' => $benchmark,
                'return_pct' => (float) $benchmarkReturn,
            ],
            'sample_size' => ['evaluations' => $events, 'fills' => $fills],
            'window' => ['started_at_ms' => $account->started_at_ms, 'as_of_ms' => now()->getTimestampMs()],
        ];
    }

    private function canonical(array $input): array
    {
        ksort($input);

        return $input;
    }

    private function d(mixed $value): string
    {
        if (is_string($value) && preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            return $value;
        }

        return number_format((float) $value, self::SCALE, '.', '');
    }
}
