<?php

namespace App\Console\Commands;

use App\Domain\MarketData\CandleQuality;
use App\Domain\MarketData\CandleTimeframe;
use App\Repositories\ExchangeRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

use function Trademinator\Time\periods_to_seconds;
use function Trademinator\Time\to_unixtime;

class SelectCandlePeriod extends Command
{
    protected $signature = 'trademinator:select-candle-period {exchange} {symbol} {--periods=1m,3m,5m,15m,30m,1h,4h,1d} {--tick-size=} {--threshold=0.7} {--coverage=0.8} {--minimum=50} {--sample=250} {--from=7 days ago} {--to=now}';

    protected $description = 'Select the shortest sufficiently informative candle period';

    public function handle(ExchangeRepository $repository, CandleQuality $quality): int
    {
        $exchange = $repository->findByClass((string) $this->argument('exchange'))?->first();
        if ($exchange === null) {
            $this->error('Exchange is not configured.');

            return self::FAILURE;
        }
        $tickSize = (float) $this->option('tick-size');
        $threshold = filter_var($this->option('threshold'), FILTER_VALIDATE_FLOAT);
        $coverage = filter_var($this->option('coverage'), FILTER_VALIDATE_FLOAT);
        $minimum = filter_var($this->option('minimum'), FILTER_VALIDATE_INT);
        $sample = filter_var($this->option('sample'), FILTER_VALIDATE_INT);
        $from = to_unixtime((string) $this->option('from'));
        $to = to_unixtime((string) $this->option('to'));
        if (! is_finite($tickSize) || $tickSize <= 0 || $from === false || $to === false || $from >= $to
            || $threshold === false || $threshold < 0 || $threshold > 1
            || $coverage === false || $coverage < 0 || $coverage > 1
            || $minimum === false || $minimum < 1 || $sample === false || $sample < $minimum) {
            $this->error('Provide a positive --tick-size, valid interval, 0..1 threshold/coverage, and sample >= minimum >= 1.');

            return self::FAILURE;
        }
        $repository->setExchange($exchange);
        $supported = array_keys($repository->periods());
        $periods = array_map('trim', explode(',', (string) $this->option('periods')));
        $periods = array_values(array_unique($periods));
        if (in_array('', $periods, true) || array_diff($periods, $supported) || array_diff($periods, CandleTimeframe::SUPPORTED)) {
            $this->error('All requested periods must be supported by the exchange and Trademinator.');

            return self::FAILURE;
        }
        usort($periods, fn (string $a, string $b): int => periods_to_seconds($a) <=> periods_to_seconds($b));
        $timeframe = new CandleTimeframe;
        $closedBefore = min($to * 1000, time() * 1000);
        foreach ($periods as $period) {
            try {
                $candles = $repository->fetch((string) $this->argument('symbol'), $period, $from, $to);
                $complete = array_values(array_filter($candles, fn (array $candle): bool =>
                    $timeframe->next((int) $candle['microtimestamp'], $period) <= $closedBefore));
                $complete = array_slice($complete, -$sample);
                $last = end($complete);
                if ($last !== false && $timeframe->next($timeframe->next((int) $last['microtimestamp'], $period), $period) < $closedBefore) {
                    continue;
                }
                $selected = $quality->choose([$period => $complete], $tickSize, $threshold, $minimum, $coverage);
            } catch (InvalidArgumentException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
            if ($selected !== null) {
                $selectionId = (string) Str::uuid7();
                DB::table('candle_period_selections')->insert([
                    'selection_id' => $selectionId,
                    'exchange' => $exchange->class,
                    'symbol' => (string) $this->argument('symbol'),
                    'period' => $period,
                    'sample_from_ms' => (int) $complete[0]['microtimestamp'],
                    'sample_to_ms' => (int) $last['microtimestamp'],
                    'threshold' => $threshold,
                    'minimum_coverage' => $coverage,
                    'quality' => json_encode($selected['quality'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
                $selected['selection_id'] = $selectionId;
                $this->line(json_encode($selected, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
        }

        $this->warn('No completed timeframe sample meets the candle-quality threshold and minimum size.');

        return self::FAILURE;
    }
}
