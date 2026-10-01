<?php

namespace App\Console\Commands;

use App\Domain\MarketData\CandleGapRepairs;
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

    public function handle(CandleGapRepairs $repairs, TickerRepository $tickers): int
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

            if ($scan && $latestCandle !== null) {
                try {
                    $repairs->scanFeed($feed);
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

            $rows[] = [
                $exchangeClass,
                $marketSymbol,
                $marketPeriod,
                $statuses->map(fn (string $status): string => strtoupper($status))->implode(', '),
                (string) $this->missingCount($repairs, $gaps),
                $this->ranges($gaps),
                $reason,
                $this->suggestion($gaps, $exchangeClass, $marketSymbol, $marketPeriod),
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

    private function suggestion(Collection $gaps, string $exchange, string $symbol, string $period): string
    {
        $statuses = $gaps->pluck('status')->filter()->unique()->all();
        $backfill = 'php artisan trademinator:backfill-ohlcv'
            .' --exchange='.escapeshellarg($exchange)
            .' --symbol='.escapeshellarg($symbol)
            .' --period='.escapeshellarg($period);
        $from = $this->isoTime((int) $gaps->min('from_ms'));
        $to = $this->isoTime((int) $gaps->max('to_ms'));
        $sync = 'php artisan trademinator:sync-ohlcv'
            .' '.escapeshellarg($exchange)
            .' '.escapeshellarg($symbol)
            .' '.escapeshellarg($period)
            .' --from='.escapeshellarg($from)
            .' --to='.escapeshellarg($to)
            .' --repair-gaps';

        if (in_array('unavailable', $statuses, true)) {
            return "The exchange repeatedly omitted these candles. Try manually: {$sync}";
        }

        if (in_array('paused', $statuses, true)) {
            return "Fix the reported access/support error, then retry: {$sync}";
        }

        if (in_array('queued', $statuses, true)) {
            return 'Repair already queued; verify the history queue worker is running.';
        }

        if (in_array('retrying', $statuses, true)) {
            return "Wait until the retry is due, then run: {$backfill}";
        }

        return "Queue the repair: {$backfill}";
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
