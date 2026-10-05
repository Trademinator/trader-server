<?php

namespace App\Domain\MarketData;

use App\Domain\Intelligence\ModelStore;
use App\Jobs\RecoverMarketHistory;
use App\Models\HumanTrainingSnapshot;
use App\Models\Market;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Owner-requested recovery uses the existing history and intelligence workers. */
final class HistoryRecovery
{
    public function request(Market $market, bool $retryUnavailable = false): string
    {
        if (! config('history_backfill.enabled') || ! config('intelligence.enabled')
            || in_array(config('queue.default'), ['sync', 'null'], true)) {
            throw ValidationException::withMessages(['recovery' => 'Enable history backfill and intelligence with a persistent queue before requesting recovery.']);
        }
        $market->loadMissing('feed', 'exchange');
        $period = $market->feed?->selected_period;
        if ($period === null || ! $market->subscriptions()->where('active', true)->exists()) {
            throw ValidationException::withMessages(['recovery' => 'Recovery requires an active subscription and a selected candle period.']);
        }
        $report = $this->modelReport($market, $period);
        $schema = $report['training_data']['schema'] ?? config('intelligence.schema');
        if (! in_array($schema, ['core', 'technical', 'full'], true)) {
            throw ValidationException::withMessages(['recovery' => 'This model has a custom schema. Repair its candles using candle-gaps/backfill-ohlcv and rebuild using the original custom dataset workflow.']);
        }

        [$id, $created] = DB::transaction(function () use ($market, $period, $schema, $retryUnavailable): array {
            DB::table('market_feeds')->where('market_id', $market->market_id)->lockForUpdate()->first();
            $existing = DB::table('history_recovery_requests')->where('market_id', $market->market_id)
                ->where('period', $period)->whereNotIn('status', ['failed', 'blocked'])->where(function ($query): void {
                    $query->where('created_at', '>', now()->subMinute())
                        ->orWhere(fn ($pending) => $pending->whereIn('status', ['queued', 'scanning'])
                            ->where('updated_at', '>', now()->subMinutes(15)));
                })->orderByDesc('created_at')->first();
            if ($existing !== null) {
                return [$existing->request_id, false];
            }
            $id = (string) Str::uuid7();
            DB::table('history_recovery_requests')->insert([
                'request_id' => $id, 'market_id' => $market->market_id, 'period' => $period,
                'schema' => $schema, 'retry_unavailable' => $retryUnavailable, 'status' => 'queued',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [$id, true];
        });
        if ($created) {
            try {
                RecoverMarketHistory::dispatch($id)->onQueue(config('history_backfill.queue'));
            } catch (\Throwable $error) {
                DB::table('history_recovery_requests')->where('request_id', $id)->update([
                    'status' => 'failed', 'error' => mb_substr($error->getMessage(), 0, 1000), 'updated_at' => now(),
                ]);
                throw $error;
            }
        }

        return $id;
    }

    public function describe(Market $market): array
    {
        $market->loadMissing('feed', 'exchange');
        $period = $market->feed?->selected_period;
        $history = DB::table('market_history_backfills')->where('market_id', $market->market_id)->where('period', $period)->first();
        $gapQuery = DB::table('candle_gap_repairs')->where('market_id', $market->market_id)->where('period', $period)
            ->where('status', '!=', 'resolved');
        $gapCounts = (clone $gapQuery)->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status')->all();
        $gaps = (clone $gapQuery)->orderByDesc('to_ms')->limit(100)->get();
        $report = $period === null ? null : $this->modelReport($market, $period);
        $dirty = $history !== null && $history->history_revision > $history->trained_revision;
        $features = $history === null ? 'not_requested' : ($dirty ? ($history->build_stage === 'knn' ? 'completed' : 'pending_or_running') : 'completed');
        $request = DB::table('history_recovery_requests')->where('market_id', $market->market_id)->where('period', $period)
            ->orderByDesc('created_at')->orderByDesc('request_id')->first();
        $reviewCount = $period === null ? 0 : HumanTrainingSnapshot::query()
            ->where('market_key', ModelStore::marketKey($market->exchange->class, $market->symbol, $period))
            ->where('payload->revision->requires_review', true)->whereDoesntHave('candleLabels')
            ->whereDoesntHave('reviews', fn ($query) => $query->whereNotNull('submitted_at'))->count();

        return compact('period', 'history', 'gapCounts', 'gaps', 'report', 'dirty', 'features', 'request', 'reviewCount');
    }

    private function modelReport(Market $market, string $period): ?array
    {
        $json = DB::table('intelligence_heads as heads')->join('intelligence_models as models', 'models.model_id', '=', 'heads.model_id')
            ->where('heads.market_key', ModelStore::marketKey($market->exchange->class, $market->symbol, $period))->value('models.report');

        return $json === null ? null : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
