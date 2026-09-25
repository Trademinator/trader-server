<?php

namespace App\Domain\MarketData;

use App\Repositories\ExchangeRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

use function Trademinator\Time\periods_to_seconds;

final class CandlePeriodSelector
{
    public function __construct(private readonly ExchangeRepository $exchanges, private readonly CandleQuality $quality) {}

    /** @param list<string> $periods
     *  @return array<string, mixed>|null
     */
    public function select(string $exchange, string $symbol, array $periods, float $tickSize, int $from, int $to, float $threshold = 0.7, float $coverage = 0.8, int $minimum = 50, int $sample = 250): ?array
    {
        if ($tickSize <= 0 || ! is_finite($tickSize) || $from >= $to || $minimum < 1 || $sample < $minimum
            || $threshold < 0 || $threshold > 1 || $coverage < 0 || $coverage > 1) {
            throw new InvalidArgumentException('Invalid candle selection settings.');
        }
        $model = $this->exchanges->findByClass($exchange)?->first();
        if ($model === null) {
            throw new InvalidArgumentException('Exchange is not configured.');
        }
        $this->exchanges->setExchange($model);
        $supported = array_keys($this->exchanges->periods());
        $periods = array_values(array_unique($periods));
        if (! $periods || in_array('', $periods, true) || array_diff($periods, $supported) || array_diff($periods, CandleTimeframe::SUPPORTED)) {
            throw new InvalidArgumentException('All requested periods must be supported by the exchange and Trademinator.');
        }
        usort($periods, fn (string $a, string $b): int => periods_to_seconds($a) <=> periods_to_seconds($b));
        $timeframe = new CandleTimeframe;
        $closedBefore = min($to * 1000, time() * 1000);
        foreach ($periods as $period) {
            $candles = $this->exchanges->fetch($symbol, $period, $from, $to);
            $complete = array_values(array_filter($candles, fn (array $candle): bool =>
                $timeframe->next((int) $candle['microtimestamp'], $period) <= $closedBefore));
            $complete = array_slice($complete, -$sample);
            $last = end($complete);
            if ($last !== false && $timeframe->next($timeframe->next((int) $last['microtimestamp'], $period), $period) < $closedBefore) {
                continue;
            }
            $selected = $this->quality->choose([$period => $complete], $tickSize, $threshold, $minimum, $coverage);
            if ($selected === null) {
                continue;
            }
            $selectionId = (string) Str::uuid7();
            DB::table('candle_period_selections')->insert([
                'selection_id' => $selectionId, 'exchange' => $model->class, 'symbol' => $symbol, 'period' => $period,
                'sample_from_ms' => (int) $complete[0]['microtimestamp'],
                'sample_to_ms' => (int) $last['microtimestamp'],
                'threshold' => $threshold, 'minimum_coverage' => $coverage,
                'quality' => json_encode($selected['quality'], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            $selected['selection_id'] = $selectionId;

            return $selected;
        }

        return null;
    }
}
