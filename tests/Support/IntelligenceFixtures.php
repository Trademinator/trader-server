<?php

namespace Tests\Support;

use App\Domain\Features\FeatureBuilder;
use App\Domain\Features\FeatureEngine;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\SemanticLabels;
use App\Models\Ticker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IntelligenceFixtures
{
    public const START = 1704067200000;

    public static function snapshot(int $count = 240, bool $contradictory = false, bool $patterns = false, string $period = '1m'): array
    {
        $rows = [];
        $timeframe = new CandleTimeframe;
        $timestamp = self::START;
        for ($i = 0; $i < $count; $i++) {
            $state = $i % 3;
            $decision = $timeframe->next($timestamp, $period);
            $patternAvailable = $timeframe->next($decision, $period);
            $rows[] = ['microtimestamp' => $timestamp,
                'decision_at_ms' => $decision,
                'label_available_at_ms' => $timeframe->next($patternAvailable, $period),
                'vector' => [$state / 2], 'label' => $contradictory ? ['bear', 'bull', 'super_bear', 'super_bull', 'neutral'][$i % 5] : ['bull', 'neutral', 'bear'][$state],
                'action_label' => ['buy', 'hodl', 'sell'][$state],
                'semantic' => ['bottom' => $contradictory ? $state === 2 : $state === 0,
                    'top' => $contradictory ? $state === 0 : $state === 2],
                'patterns' => $patterns ? [['type' => 'bullish_engulfing', 'length' => 2, 'stage' => 1,
                    'progress' => 0.5, 'similarity' => 0.8, 'label' => $state === 0 ? 'completed' : 'failed',
                    'label_available_at_ms' => $patternAvailable]] : []];
            $timestamp = $decision;
        }
        $id = (string) Str::uuid7();
        $bytes = implode('', array_map(fn (array $row): string => json_encode($row)."\n", $rows));
        $manifest = ['dataset_id' => $id, 'format_version' => 'm3-dataset-v1',
            'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => $period, 'keys' => ['candle.body'],
            'feature_version' => FeatureEngine::VERSION, 'schema' => 'custom',
            'label_definition' => (new SemanticLabels(2, 3))->metadata(),
            'as_of_ms' => $timeframe->next($timeframe->next($timeframe->next($timestamp, $period), $period), $period), 'rows' => $count,
            'rows_sha256' => hash('sha256', $bytes)];
        $path = app(DatasetStore::class)->directory($id);
        mkdir($path, 0700, true);
        file_put_contents($path.'/manifest.json', json_encode($manifest));
        file_put_contents($path.'/rows.jsonl', $bytes);
        DB::table('research_datasets')->insert(['dataset_id' => $id, 'manifest' => json_encode($manifest), 'created_at' => now()]);

        return $manifest;
    }

    public static function candles(int $count = 110): void
    {
        for ($i = 0; $i < $count; $i++) {
            $open = 100 + 10 * sin($i * M_PI / 10);
            $close = 100 + 10 * sin(($i + 1) * M_PI / 10);
            Ticker::query()->create(['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
                'microtimestamp' => self::START + $i * 60000,
                'payload' => json_encode(['open' => (string) $open, 'close' => (string) $close,
                    'high' => (string) (max($open, $close) + 0.2), 'low' => (string) (min($open, $close) - 0.2),
                    'volume' => '10'])]);
        }
        app(FeatureBuilder::class)->build('kraken', 'BTC/USD', '1m', self::START + $count * 60000);
    }

    public static function feature(int $minute, float $body): void
    {
        $timestamp = self::START + $minute * 60000;
        Ticker::query()->create(['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
            'microtimestamp' => $timestamp, 'payload' => json_encode(['open' => '10', 'close' => '10',
                'high' => '11', 'low' => '9', 'volume' => '1'])]);
        DB::table('market_features')->insert(['feature_id' => (string) Str::uuid7(),
            'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m', 'microtimestamp' => $timestamp,
            'available_at_ms' => $timestamp + 60000, 'version' => FeatureEngine::VERSION,
            'payload' => json_encode(['microtimestamp' => $timestamp, 'available_at_ms' => $timestamp + 60000,
                'version' => FeatureEngine::VERSION, 'close' => 10.0, 'features' => ['candle.body' => $body]]),
            'created_at' => now(), 'updated_at' => now()]);
    }
}
