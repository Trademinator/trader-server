<?php

namespace App\Domain\MarketData;

use App\Models\Exchange;
use App\Models\Market;
use App\Repositories\ExchangeRepository;
use Illuminate\Support\Facades\Cache;
use Throwable;

use function Trademinator\Time\periods_to_seconds;

/** Read-only, cached CCXT choices for the market subscription form. */
final class MarketCatalog
{
    public function __construct(private readonly ExchangeRepository $repository) {}

    /** @return list<array{value: string, label: string, logo_url: ?string}> */
    public function exchanges(): array
    {
        return Cache::remember('trademinator:market-catalog:exchanges:v2', 3600, function (): array {
            $choices = [];
            foreach (Exchange::query()->orderBy('class')->get()->groupBy('class') as $group) {
                if ($group->count() !== 1) {
                    continue;
                }
                $exchange = $group->first();
                try {
                    $this->repository->setExchange($exchange);
                    $description = $this->repository->describe();
                    if (! array_intersect(array_keys($description['timeframes'] ?? []), CandleTimeframe::SUPPORTED)) {
                        continue;
                    }
                    $choices[] = [
                        'value' => $exchange->class,
                        'label' => (string) (($description['name'] ?? null) ?: $exchange->name),
                        'logo_url' => self::logoUrl(is_array($description['urls'] ?? null)
                            ? ($description['urls']['logo'] ?? null) : null),
                    ];
                } catch (Throwable) {
                    // A configured exchange may have been removed by a CCXT update.
                    continue;
                }
            }
            usort($choices, fn (array $a, array $b): int =>
                strcasecmp($a['label'], $b['label']) ?: strcmp($a['value'], $b['value']));

            return $choices;
        });
    }

    /** @return array{symbols: list<array{value: string, tick_size: ?string}>, periods: list<array{value: string, label: string}>} */
    public function forExchange(Exchange $exchange): array
    {
        $options = Cache::remember('trademinator:market-catalog:'.$exchange->exchange_id, 300, function () use ($exchange): array {
            $this->repository->setExchange($exchange);
            $description = $this->repository->describe();
            $periods = array_values(array_intersect(array_keys($description['timeframes'] ?? []), CandleTimeframe::SUPPORTED));
            usort($periods, fn (string $a, string $b): int => periods_to_seconds($a) <=> periods_to_seconds($b));
            $mode = $description['precisionMode'] ?? null;
            $symbols = [];
            if ($periods !== []) {
                foreach ($this->repository->markets() as $symbol => $market) {
                    if (! is_array($market) || ! is_string($symbol) || strlen($symbol) > 32
                        || ! preg_match('/^[A-Z0-9._-]+\/[A-Z0-9._-]+$/D', $symbol)
                        || ($market['spot'] ?? ($market['type'] ?? null) === 'spot') === false) {
                        continue;
                    }
                    $symbols[] = [
                        'value' => $symbol,
                        'tick_size' => self::tickSize($market['precision']['price'] ?? null, $mode),
                    ];
                }
            }
            usort($symbols, fn (array $a, array $b): int => strcmp($a['value'], $b['value']));

            return [
                'symbols' => $symbols,
                'periods' => array_map(fn (string $period): array =>
                    ['value' => $period, 'label' => self::periodLabel($period)], $periods),
            ];
        });

        // Existing subscriptions retain their persisted price increment even if
        // current CCXT metadata lacks precision for that symbol.
        $stored = Market::query()->where('exchange_id', $exchange->exchange_id)->pluck('tick_size', 'symbol');
        foreach ($options['symbols'] as &$symbol) {
            if ($stored->has($symbol['value'])) {
                $symbol['tick_size'] = (string) $stored->get($symbol['value']);
            }
        }
        unset($symbol);

        return $options;
    }

    public function tickSizeFor(Exchange $exchange, string $symbol): ?string
    {
        foreach ($this->forExchange($exchange)['symbols'] as $choice) {
            if ($choice['value'] === $symbol) {
                return $choice['tick_size'];
            }
        }

        return null;
    }

    public static function tickSize(mixed $pricePrecision, mixed $mode): ?string
    {
        if (! is_numeric($pricePrecision)) {
            return null;
        }
        if ($mode === \ccxt\DECIMAL_PLACES && (int) $pricePrecision == $pricePrecision
            && (int) $pricePrecision >= -11 && (int) $pricePrecision <= 18) {
            $places = (int) $pricePrecision;

            return $places < 0 ? '1'.str_repeat('0', -$places)
                : ($places === 0 ? '1' : '0.'.str_repeat('0', $places - 1).'1');
        }
        // Significant digits do not define a fixed price increment.
        if ($mode !== \ccxt\TICK_SIZE || (float) $pricePrecision <= 0 || ! is_finite((float) $pricePrecision)) {
            return null;
        }
        $normalized = rtrim(rtrim(sprintf('%.18F', (float) $pricePrecision), '0'), '.');

        return preg_match('/^\d{1,12}(?:\.\d{1,18})?$/D', $normalized) && (float) $normalized > 0
            ? $normalized : null;
    }

    private static function logoUrl(mixed $url): ?string
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            && parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : null;
    }

    private static function periodLabel(string $period): string
    {
        preg_match('/^(\d+)([mhdwMy])$/D', $period, $parts);
        $quantity = (int) $parts[1];
        $unit = match ($parts[2]) {
            'm' => 'minute', 'h' => 'hour', 'd' => 'day',
            'w' => 'week', 'M' => 'month', 'y' => 'year',
        };

        return $quantity.' '.$unit.($quantity === 1 ? '' : 's');
    }
}
