<?php

namespace App\Repositories;

use App\Domain\Archive\ArchiveIntegrityException;
use App\Domain\Archive\PortableJson;
use App\Domain\Archive\TickerArchive;
use App\Domain\MarketData\ExchangeCredentials;
use App\Domain\Operations\ActionLog;
use App\Models\Ticker;
use App\Traits\TickerManipulation;
use ccxt\Exchange;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JasonGuru\LaravelMakeRepository\Repository\BaseRepository;

/**
 * Class TickerRepository.
 */
class TickerRepository extends BaseRepository
{
    use TickerManipulation;

    protected ?Ticker $ticker;

    protected ?Exchange $ccxtExchange = null;

    public function __construct(?Ticker $ticker = null)
    {
        parent::__construct();
        $this->ticker = $ticker;
    }

    public function fetch(string $symbol, string $period, int $startFetching, int $records, array $params): array
    {
        $log = app(ActionLog::class);
        $started = hrtime(true);
        $fields = ['exchange' => $this->ccxtExchange?->id, 'symbol' => $symbol, 'period' => $period];
        try {
            $myTickers = $this->ccxtExchange->fetch_ohlcv($symbol, $period, $startFetching, $records, $params);
            $this->normalize_ticker($myTickers, true);
        } catch (\Throwable $error) {
            $log->write('exchange.ohlcv_fetched', [...$fields, 'outcome' => 'failed',
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), ...$log->exception($error)]);
            throw ExchangeCredentials::safeFailure($error);
        }
        $log->write('exchange.ohlcv_fetched', [...$fields, 'outcome' => 'completed', 'rows' => count($myTickers),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000)]);

        if (App::hasDebugModeEnabled()) {
            Log::debug('myTickers: '.print_r($myTickers, true));
        }

        return $myTickers;
    }

    public function fetchFromDB(string $exchange, string $symbol, string $period, ?int $startFetching = null, ?int $endFetching = null): array
    {
        $tickers = [];
        foreach ($this->streamHistory($exchange, $symbol, $period, $startFetching, $endFetching) as $raw) {
            $tickers[] = $raw;
        }

        return $tickers;
    }

    /**
     * Chronological hot+cold history. Only the required archive shards are
     * decompressed, and the two sorted streams are merged one row at a time.
     * Identical overlap is accepted; conflicting overlap is an integrity error.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function streamHistory(string $exchange, string $symbol, string $period, ?int $fromMs = null, ?int $toMs = null): \Generator
    {
        $fromMs ??= 0;
        $toMs ??= PHP_INT_MAX;
        $query = Ticker::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
            ->whereBetween('microtimestamp', [$fromMs, $toMs])->orderBy('microtimestamp');
        if (App::hasDebugModeEnabled()) {
            Log::debug('Hot ticker SQL: '.$query->toRawSql());
        }
        $hot = (function () use ($query): \Generator {
            foreach ($query->lazy(500) as $ticker) {
                $payload = json_decode($ticker->payload, true, flags: JSON_THROW_ON_ERROR);
                $payload['microtimestamp'] = (int) $ticker->microtimestamp;
                yield (int) $ticker->microtimestamp => $payload;
            }
        })();
        $cold = config('archive.enabled')
            ? app(TickerArchive::class)->stream($exchange, $symbol, $period, $fromMs, $toMs)
            : (function (): \Generator {
                if (false) {
                    yield;
                }
            })();
        $hot->rewind();
        $cold->rewind();
        while ($hot->valid() || $cold->valid()) {
            if (! $cold->valid() || ($hot->valid() && $hot->key() < $cold->key())) {
                yield (int) $hot->key() => $hot->current();
                $hot->next();

                continue;
            }
            $coldRecord = $cold->current();
            $coldPayload = $coldRecord['payload'];
            $coldPayload['microtimestamp'] = (int) $cold->key();
            if (! $hot->valid() || $cold->key() < $hot->key()) {
                yield (int) $cold->key() => $coldPayload;
                $cold->next();

                continue;
            }
            if (PortableJson::encode($hot->current()) !== PortableJson::encode($coldPayload)) {
                throw new ArchiveIntegrityException("Hot/cold ticker conflict at {$exchange}|{$symbol}|{$period}|{$hot->key()}.");
            }
            yield (int) $hot->key() => $hot->current();
            $hot->next();
            $cold->next();
        }
    }

    public function latestTimestamp(string $exchange, string $symbol, string $period): ?int
    {
        $timestamp = Ticker::query()->where('exchange', $exchange)->where('symbol', $symbol)
            ->where('period', $period)->max('microtimestamp');

        $archived = config('archive.enabled') ? DB::table('archive_catalog')->where('logical_type', 'tickers')
            ->where('verification_state', 'verified')->where('exchange', $exchange)->where('symbol', $symbol)
            ->where('period', $period)->max('range_end_ms') : null;
        if ($timestamp === null && $archived === null) {
            return null;
        }

        return max((int) ($timestamp ?? 0), (int) ($archived ?? 0));
    }

    /** @return list<int> */
    public function timestamps(string $exchange, string $symbol, string $period, int $fromMs, int $toMs): array
    {
        $timestamps = [];
        foreach ($this->streamHistory($exchange, $symbol, $period, $fromMs, $toMs) as $timestamp => $_) {
            $timestamps[] = $timestamp;
        }

        return $timestamps;
    }

    // array_merge breaks timestamp keys, so normalize and reindex canonically.
    public function fixTickerIndex(array $tickers): array
    {
        return $this->normalize_ticker($tickers, true);
    }

    /**
     * @return string Return the model
     */
    public function model(): string
    {
        return Ticker::class;
    }

    public function update(array $data, array $unique, array $update): int
    {
        // Eloquent's bulk upsert bypasses model creating events. Generate UUIDs
        // before the insert path, while preserving explicitly supplied IDs.
        foreach ($data as &$row) {
            if (empty($row['ticker_id'])) {
                $row['ticker_id'] = (string) Str::uuid7();
            }
        }
        unset($row);

        return Ticker::query()->upsert($data, $unique, $update);
    }

    public function updateTickers(string $exchange, string $symbol, string $period, array $tickers): int
    {
        $unique = ['exchange', 'symbol', 'period', 'microtimestamp'];
        $update = ['payload'];
        $affected = 0;

        foreach (array_chunk($tickers, 100) as $chunk) {
            $data = [];

            foreach ($chunk as $ticker) {
                $data[] = [
                    'exchange' => $exchange,
                    'symbol' => $symbol,
                    'period' => $period,
                    'microtimestamp' => $ticker['microtimestamp'],
                    'payload' => json_encode($ticker, JSON_THROW_ON_ERROR),
                ];
            }

            $affected += $this->update($data, $unique, $update);
        }

        return $affected;
    }

    public function saveTickers(string $exchange, string $symbol, string $period, array $tickers): int
    {
        return $this->updateTickers($exchange, $symbol, $period, $tickers);
    }

    public function setExchange(?Exchange $exchange): void
    {
        $this->ccxtExchange = $exchange;
    }

    public function setTicker(Ticker $ticker): void
    {
        $this->ticker = $ticker;
    }
}
