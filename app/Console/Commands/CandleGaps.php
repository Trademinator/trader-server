<?php

namespace App\Console\Commands;

use App\Domain\MarketData\CandleGapRepairs;
use App\Domain\MarketData\CandleGaps as GapRanges;
use App\Domain\MarketData\CandleTimeframe;
use App\Repositories\TickerRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class CandleGaps extends Command
{
    protected $signature = 'trademinator:candle-gaps {--exchange=} {--symbol=} {--period=} {--no-scan}';

    protected $description = 'Scan and report missing closed candles for active subscribed market feeds with suggested fixes';

    public function handle(CandleGapRepairs $repairs, TickerRepository $tickers, GapRanges $ranges): int
    {
        $exchange = $this->option('exchange');
        $symbol = $this->option('symbol');
        $period = $this->option('period');
        $scan = ! $this->option('no-scan');

        if ($period !== null && ! in_array($period, CandleTimeframe::SUPPORTED, true)) {
            $this->error('Use a supported candle period.');

            return self::FAILURE;
        }

        $rows = [];
        $commands = [];
        $feedCount = 0;
        $problemCount = 0;

        foreach ($repairs->feeds($exchange, $symbol, $period)->lazyById(100, 'market_id') as $feed) {
            $feedCount++;
            $feed->loadMissing('market.exchange');

            $exchangeClass = $feed->market->exchange->class;
            $marketSymbol = $feed->market->symbol;
            $marketPeriod = (string) $feed->selected_period;
            $latestCandle = $tickers->latestTimestamp($exchangeClass, $marketSymbol, $marketPeriod);
            $scanError = null;
            $scanBusy = false;

            if ($scan && $latestCandle !== null) {
                try {
                    $scanBusy = $repairs->scanFeed($feed) === null;
                } catch (Throwable $error) {
                    report($error);
                    $scanError = $error->getMessage();
                }
            }

            $gaps = DB::table('candle_gap_repairs')
                ->where('market_id', $feed->market_id)
                ->where('period', $marketPeriod)
                ->where('status', '!=', 'resolved')
                ->orderBy('from_ms')
                ->orderBy('to_ms')
                ->get();

            if ($latestCandle === null) {
                $problemCount++;
                $rows[] = [
                    $exchangeClass,
                    $marketSymbol,
                    $marketPeriod,
                    'NO DATA',
                    '—',
                    '—',
                    'No stored candles',
                    'Run php artisan trademinator:dispatch-market-feeds, then run this command again.',
                ];

                continue;
            }

            if ($scanError !== null) {
                $problemCount++;
                $rows[] = [
                    $exchangeClass,
                    $marketSymbol,
                    $marketPeriod,
                    'SCAN ERROR',
                    '?',
                    '—',
                    Str::limit($this->singleLine($scanError), 80),
                    'Fix the scan error and rerun php artisan trademinator:candle-gaps.',
                ];

                continue;
            }

            if ($scanBusy) {
                $problemCount++;
                $rows[] = [
                    $exchangeClass,
                    $marketSymbol,
                    $marketPeriod,
                    'SCAN BUSY',
                    '?',
                    '—',
                    'Another market-data worker is currently updating this feed.',
                    'Rerun php artisan trademinator:candle-gaps after the current worker finishes.',
                ];

                continue;
            }

            if ($gaps->isEmpty()) {
                $rows[] = [
                    $exchangeClass,
                    $marketSymbol,
                    $marketPeriod,
                    $scan ? 'OK' : 'NO TRACKED GAPS',
                    '0',
                    '—',
                    '—',
                    $scan ? 'None.' : 'Run without --no-scan for a fresh verification.',
                ];

                continue;
            }

            $problemCount++;
            $statuses = $gaps->pluck('status')->filter()->unique()->values();
            $reasons = $gaps->pluck('reason')->filter()->unique()->values();
            $lastError = $gaps->pluck('last_error')->filter()->last();
            $reason = $reasons->isEmpty() ? '—' : $reasons->implode(', ');

            if ($lastError !== null) {
                $reason .= ' · '.Str::limit($this->singleLine((string) $lastError), 80);
            }

            $merged = collect($ranges->merge($gaps->map(fn (object $gap): array => [
                'from' => (int) $gap->from_ms, 'to' => (int) $gap->to_ms,
            ])->all(), $marketPeriod))->map(fn (array $range): object => (object) [
                'from_ms' => $range['from'], 'to_ms' => $range['to'], 'period' => $marketPeriod,
            ]);
            foreach ($merged as $gap) {
                $commands[] = $this->syncCommand($exchangeClass, $marketSymbol, $marketPeriod, $gap->from_ms, $gap->to_ms);
            }

            $rows[] = [
                $exchangeClass,
                $marketSymbol,
                $marketPeriod,
                $statuses->map(fn (string $status): string => strtoupper($status))->implode(', '),
                (string) $this->missingCount($repairs, $merged),
                $this->ranges($merged),
                $reason,
                $this->suggestion($gaps),
            ];
        }

        if ($feedCount === 0) {
            $this->info('No active subscribed market feeds matched the selected filters.');

            return self::SUCCESS;
        }

        usort($rows, fn (array $left, array $right): int => [$left[0], $left[1], $left[2]] <=> [$right[0], $right[1], $right[2]]);

        $this->table(
            ['Exchange', 'Symbol', 'Period', 'Status', 'Missing', 'Range(s)', 'Reason / error', 'Suggested fix'],
            $rows,
        );

        if ($commands !== []) {
            $this->newLine();
            $this->comment('Manual repair commands (one per consecutive missing range):');
            foreach ($commands as $command) {
                $this->line($command);
            }
        }

        if (! $scan) {
            $this->comment('No local history scan was performed because --no-scan was supplied.');
        }

        if ($problemCount === 0) {
            $this->info("No missing closed candles found across {$feedCount} active feed(s).");
        } else {
            $this->warn("{$problemCount} of {$feedCount} active feed(s) need attention.");
        }

        return self::SUCCESS;
    }

    private function missingCount(CandleGapRepairs $repairs, Collection $gaps): int
    {
        return $gaps->sum(fn (object $gap): int => $repairs->expectedCount(
            (string) $gap->period,
            (int) $gap->from_ms,
            (int) $gap->to_ms,
        ));
    }

    private function ranges(Collection $gaps): string
    {
        $ranges = $gaps->take(3)->map(function (object $gap): string {
            $from = $this->displayTime((int) $gap->from_ms);
            $to = $this->displayTime((int) $gap->to_ms);

            return $from === $to ? $from : "{$from} → {$to}";
        })->implode(', ');
        $remaining = $gaps->count() - min(3, $gaps->count());

        return $remaining > 0 ? "{$ranges} +{$remaining} more" : $ranges;
    }

    private function suggestion(Collection $gaps): string
    {
        $statuses = $gaps->pluck('status')->filter()->unique()->all();

        if (in_array('unavailable', $statuses, true)) {
            return 'The exchange repeatedly omitted these candles. Manual retry commands are listed below.';
        }

        if (in_array('paused', $statuses, true)) {
            return 'Fix the reported access/support error, then use the commands below.';
        }

        if (in_array('queued', $statuses, true)) {
            return 'Repair already queued; verify the history queue worker is running.';
        }

        if (in_array('retrying', $statuses, true)) {
            return 'Automatic retry is pending; manual repair commands are listed below.';
        }

        return 'Run the sync command(s) below for the missing ranges.';
    }

    private function syncCommand(string $exchange, string $symbol, string $period, int $fromMs, int $toMs): string
    {
        if ($fromMs === $toMs) {
            $timeframe = new CandleTimeframe;
            $fromMs = $timeframe->previous($fromMs, $period);
            $toMs = $timeframe->next($toMs, $period);
        }

        return 'php artisan trademinator:sync-ohlcv'
            .' '.escapeshellarg($exchange)
            .' '.escapeshellarg($symbol)
            .' '.escapeshellarg($period)
            .' --from='.escapeshellarg($this->isoTime($fromMs))
            .' --to='.escapeshellarg($this->isoTime($toMs))
            .' --repair-gaps';
    }

    private function displayTime(int $milliseconds): string
    {
        return gmdate('Y-m-d H:i:s', intdiv($milliseconds, 1000)).'Z';
    }

    private function isoTime(int $milliseconds): string
    {
        return gmdate('Y-m-d\\TH:i:s\\Z', intdiv($milliseconds, 1000));
    }

    private function singleLine(string $value): string
    {
        return preg_replace('/\\s+/', ' ', trim($value)) ?? trim($value);
    }
}
