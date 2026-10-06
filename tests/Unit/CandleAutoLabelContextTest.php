<?php

use App\Domain\Intelligence\CandleTraining;
use App\Domain\Research\DatasetRows;
use App\Domain\Research\DatasetStore;
use Symfony\Component\Process\Process;

/** Build the real disk index without retaining decoded dataset rows in memory. */
function indexedAutoLabelContextRows(iterable $candles): DatasetRows
{
    $file = tmpfile();
    $index = tmpfile();
    if ($file === false || $index === false) {
        throw new RuntimeException('Could not create temporary dataset files.');
    }
    $position = $offset = 0;
    foreach ($candles as $candle) {
        $microtimestamp = 1704067200000 + $position * 300000;
        $row = ['candle' => $candle, 'microtimestamp' => $microtimestamp, 'decision_at_ms' => $microtimestamp + 300000];
        $line = json_encode($row, JSON_THROW_ON_ERROR)."\n";
        DatasetStore::write($index, DatasetRows::indexEntry($offset, $row, $line));
        DatasetStore::write($file, $line);
        $offset += strlen($line);
        $position++;
    }

    return new DatasetRows($file, $index, $position);
}

function runAutoLabelContextPipeline(CandleTraining $training, array &$tickers): void
{
    (new ReflectionMethod(CandleTraining::class, 'candle_auto_mark_hold_candidates'))->invokeArgs($training, [&$tickers]);
    $training->mark_all_blacks_and_whites($tickers);
    $training->remove_consequitive_actions($tickers);
    $training->remove_unprofitable_transactions($tickers, '0.008');
    $training->remove_zigzags($tickers, '0.008');
    $training->find_new_bottoms($tickers);
    $training->remove_consequitive_actions($tickers);
    $training->hodl_all_dojis($tickers);
    $training->hodl_middle_chains($tickers);
    $training->unlabel_endpoints($tickers);
}

it('retains only the close and the three flags consumed by auto-label passes', function () {
    $candles = [
        ['open' => '10', 'high' => '11', 'low' => '8', 'close' => '9', 'volume' => '1'],
        ['open' => '9', 'high' => '11', 'low' => '8', 'close' => '10', 'volume' => '1'],
        ['open' => '10', 'high' => '10', 'low' => '10', 'close' => '10', 'volume' => '0'],
        ['open' => '10', 'high' => '11', 'low' => '9', 'close' => '10', 'volume' => '1'],
        ['open' => '0.000000000000000011', 'high' => '0.000000000000000012',
            'low' => '0.000000000000000009', 'close' => '0.000000000000000010', 'volume' => '1'],
    ];
    $training = (new ReflectionClass(CandleTraining::class))->newInstanceWithoutConstructor();
    $rows = indexedAutoLabelContextRows($candles);
    $compact = (new ReflectionMethod(CandleTraining::class, 'autoLabelContext'))->invoke($training, $rows);
    $training->candle_anatomy($candles);
    $keys = array_flip(['close', 'is_black()', 'is_white()', 'is_super_doji()']);

    expect($compact)->toBe(array_map(fn (array $candle): array => array_intersect_key($candle, $keys), $candles));
    foreach ($compact as $ticker) {
        expect(array_keys($ticker))->toBe(array_keys($keys));
    }
});

it('preserves every action and endpoint across mixed candles and long chains', function (int $seed) {
    $random = new Random\Randomizer(new Random\Engine\Mt19937($seed));
    $candles = [];
    for ($index = 0; $index < 600; $index++) {
        $price = $random->getInt(9000, 11000);
        $direction = match ($index % 30) {
            0, 1, 2, 3, 4, 5 => -1,
            6, 7, 8, 9, 10, 11 => 1,
            default => $random->getInt(-1, 1),
        };
        $flat = $index % 7 === 0;
        $open = (string) $price;
        $candles[] = ['open' => $open, 'high' => $flat ? $open : (string) ($price + 200),
            'low' => $flat ? $open : (string) ($price - 200),
            'close' => $flat ? $open : (string) ($price + $direction * 100), 'volume' => '1'];
    }
    $training = (new ReflectionClass(CandleTraining::class))->newInstanceWithoutConstructor();
    $compact = (new ReflectionMethod(CandleTraining::class, 'autoLabelContext'))->invoke($training, indexedAutoLabelContextRows($candles));
    $training->candle_anatomy($candles);
    runAutoLabelContextPipeline($training, $candles);
    runAutoLabelContextPipeline($training, $compact);

    foreach ($candles as $index => $candle) {
        expect($compact[$index]['action'] ?? null)->toBe($candle['action'] ?? null);
    }
    expect($compact[0])->not->toHaveKey('action')
        ->and($compact[599])->not->toHaveKey('action');
})->with([42, 123, 20261006]);

it('processes one hundred thousand disk-indexed candles inside a 128 MiB child process', function () {
    $process = new Process([
        PHP_BINARY, '-d', 'memory_limit=128M',
        dirname(__DIR__).'/Support/candle-auto-label-memory-check.php', '100000', 'compact',
    ], dirname(__DIR__, 2));
    $process->setTimeout(120);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['rows'])->toBe(100000)
        ->and($result['memory_limit'])->toBe('128M')
        ->and($result['peak_bytes'])->toBeLessThan(96 * 1024 * 1024)
        ->and($result['first_action'])->toBeNull()
        ->and($result['last_action'])->toBeNull();
});
