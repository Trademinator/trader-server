<?php

declare(strict_types=1);

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\CandleTraining;
use App\Domain\MarketData\ClosedCandleAggregator;
use App\Domain\MarketData\OhlcvNormalizer;
use App\Repositories\TickerRepository;
use App\Traits\CandleAutoDetection;
use App\Traits\Indexing;
use Composer\InstalledVersions;
use Trademinator\Indicators\Traits\Patterns;
use Trademinator\Indicators\Traits\Technical;
use Trademinator\Indicators\Traits\TickerManipulation;

use function Trademinator\BcMath\bcabs;
use function Trademinator\BcMath\bcconv;
use function Trademinator\BcMath\bcdec;
use function Trademinator\BcMath\bcmax;
use function Trademinator\BcMath\bcmin;
use function Trademinator\BcMath\bcstddev;

it('loads the audited published releases and not application algorithm copies', function () {
    foreach ([
        'trademinator/bcmath' => '8f6ee55f8d240f9883ea31ccc4336be8a284db2e',
        'trademinator/indicators' => '90521bf48a59e66ab7df3c1216898e3ac0e2a001',
    ] as $package => $reference) {
        expect(InstalledVersions::isInstalled($package))->toBeTrue()
            ->and(InstalledVersions::getVersion($package))->toBe('0.1.0.0')
            ->and(InstalledVersions::getReference($package))->toBe($reference);
    }

    foreach (['Bc', 'Technical', 'Patterns', 'TickerManipulation'] as $name) {
        expect(trait_exists('App\\Traits\\'.$name))->toBeFalse()
            ->and(is_file(dirname(__DIR__, 2).'/app/Traits/'.$name.'.php'))->toBeFalse();
    }

    $root = realpath(InstalledVersions::getInstallPath('trademinator/indicators'));
    expect($root)->not->toBeFalse();
    foreach ([Technical::class, Patterns::class, TickerManipulation::class] as $trait) {
        $file = realpath((new ReflectionClass($trait))->getFileName());
        expect($file)->not->toBeFalse()
            ->and(str_starts_with($file, $root.DIRECTORY_SEPARATOR))->toBeTrue();
    }
});

it('wires every direct application consumer to the published traits', function () {
    expect(class_uses(FeatureEngine::class))->toHaveKey(Technical::class)
        ->and(class_uses(OhlcvNormalizer::class))->toHaveKey(TickerManipulation::class)
        ->and(class_uses(TickerRepository::class))->toHaveKey(TickerManipulation::class)
        ->and(class_uses(Indexing::class))->toHaveKey(TickerManipulation::class)
        ->and(class_uses(CandleAutoDetection::class))->toHaveKey(Patterns::class)
        ->and(class_uses(CandleTraining::class))->toHaveKey(CandleAutoDetection::class);

    foreach ([FeatureEngine::class, CandleTraining::class, ClosedCandleAggregator::class,
        OhlcvNormalizer::class, TickerRepository::class, Indexing::class] as $class) {
        foreach (['bcconv', 'bcdec', 'bcabs', 'bcmax', 'bcmin', 'stats_standard_deviation'] as $method) {
            expect(method_exists($class, $method))->toBeFalse();
        }
    }
});

it('uses namespaced decimal helpers including exact extrema and standard deviation', function () {
    expect(bcconv('1.23456789123456789e-7'))->toBe('0.000000123456789123456789')
        ->and(bcdec(['100']))->toBe(0)
        ->and(bcdec(['1.230000'], trimTrailingZeros: true))->toBe(2)
        ->and(bccomp(bcmax('0.000000001', 1.0e-8), '0.00000001', 32))->toBe(0)
        ->and(bcmin('0.000000001', 1.0e-8))->toBe('0.000000001')
        ->and(bcstddev(['1', '3'], sample: false, scale: 16))->toBe('1.0000000000000000');
});

it('preserves the application aggregation decimal floor for integer volumes', function () {
    $source = [];
    for ($i = 0; $i < 5; $i++) {
        $source[] = ['microtimestamp' => $i * 60_000,
            'open' => '10', 'high' => '12', 'low' => '9', 'close' => '11', 'volume' => '1'];
    }

    $rows = iterator_to_array((new ClosedCandleAggregator)->rows($source, '1m', '5m', 300_000));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['open'])->toBe('10')
        ->and($rows[0]['close'])->toBe('11')
        ->and($rows[0]['volume'])->toBe('5.00');
});

it('normalizes a CCXT page through the package without narrowing its decimal strings', function () {
    $raw = [[1_700_000_000_123, '1e-20', '1.2e-20', '0.9e-20', '1.1e-20', null]];
    $rows = (new OhlcvNormalizer)->normalize($raw, true);
    $row = $rows[1_700_000_000];

    expect($row['microtimestamp'])->toBe(1_700_000_000_123)
        ->and($row['open'])->toBe('0.00000000000000000001')
        ->and($row['close'])->toBe('0.000000000000000000011')
        ->and($row['volume'])->toBe('0');

    $adapter = new class
    {
        use Indexing;
    };
    $copy = $raw;
    expect($adapter->normalize($copy, true))->toBe($rows)
        ->and($copy)->toBe($rows);
});

it('keeps the metric formulas for tiny candles instead of restoring legacy truncation', function () {
    $engine = new FeatureEngine;
    $geometry = $engine->technical_candle_geometry('1e-19', '1.2e-19', '9e-20', '1.1e-19');

    foreach (['body', 'upper_wick', 'lower_wick'] as $part) {
        $error = bcabs(bcsub($geometry[$part], bcdiv('1', '3', 32), 32));
        expect(bccomp($error, '0.000000000001', 32))->toBe(-1);
    }
    expect(bccomp($engine->technical_relative_change_value('1.1e-19', '1e-19'), '0.1', 16))->toBe(0);
});

it('keeps tiny-price features causal and identical across retained slice boundaries', function () {
    $source = [];
    for ($i = 0; $i < 90; $i++) {
        $close = bcmul((string) (100 + ($i % 11)), '0.00000000000000000001', 24);
        $source[] = ['microtimestamp' => 1_700_000_000_000 + $i * 60_000,
            'open' => $close, 'close' => $close,
            'high' => bcadd($close, '0.00000000000000000001', 24),
            'low' => bcsub($close, '0.00000000000000000001', 24), 'volume' => '1'];
    }
    $original = $source;
    $engine = new FeatureEngine;
    $batch = iterator_to_array($engine->rows($source, '1m', PHP_INT_MAX, 500));
    $sliced = iterator_to_array($engine->rows($source, '1m', PHP_INT_MAX, 7));
    $prefix = iterator_to_array($engine->rows(array_slice($source, 0, 40), '1m', PHP_INT_MAX, 7));

    expect($sliced)->toBe($batch)
        ->and($prefix)->toBe(array_slice($batch, 0, 40))
        ->and($source)->toBe($original)
        ->and(end($batch)['features']['volatility.atrp_3'])->toBeGreaterThan(0)
        ->and(FeatureEngine::VERSION)->toBe('m2-v6');
});
