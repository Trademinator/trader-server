<?php

/**
 * Isolated, disk-indexed auto-label regression probe. No Laravel bootstrap,
 * database connection, exchange request, snapshot write or saved label write.
 *
 * php -d memory_limit=128M tests/Support/candle-auto-label-memory-check.php 100000 compact
 * php -d memory_limit=512M tests/Support/candle-auto-label-memory-check.php 100000 legacy
 */

use App\Domain\Intelligence\CandleTraining;
use App\Domain\Research\DatasetRows;
use App\Domain\Research\DatasetStore;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$count = filter_var($argv[1] ?? '100000', FILTER_VALIDATE_INT);
$mode = $argv[2] ?? 'compact';
if ($count === false || $count < 3 || $count > 500000 || ! in_array($mode, ['compact', 'legacy'], true)) {
    fwrite(STDERR, "Usage: php probe.php <3..500000 rows> <compact|legacy>\n");
    exit(2);
}

$file = tmpfile();
$index = tmpfile();
if ($file === false || $index === false) {
    throw new RuntimeException('Could not create temporary dataset files.');
}
$offset = 0;
for ($position = 0; $position < $count; $position++) {
    // Alternating regimes include both trade directions, same-colour chains,
    // full-flat dojis, and ordinary dojis. Prices remain decimal strings.
    $price = 10000 + (($position * 31) % 601);
    $direction = [0, -1, -1, -1, -1, -1, 1, 1, 1, 1, 1, 0][$position % 12];
    $open = (string) $price;
    $close = (string) ($price + $direction * 70);
    $flat = $position % 12 === 0;
    $microtimestamp = 1704067200000 + $position * 300000;
    $row = [
        'microtimestamp' => $microtimestamp,
        'decision_at_ms' => $microtimestamp + 300000,
        'candle' => [
            'open' => $open, 'high' => $flat ? $open : (string) ($price + 100),
            'low' => $flat ? $open : (string) ($price - 100),
            'close' => $close, 'volume' => '1.00000000',
        ],
    ];
    $line = json_encode($row, JSON_THROW_ON_ERROR)."\n";
    DatasetStore::write($index, DatasetRows::indexEntry($offset, $row, $line));
    DatasetStore::write($file, $line);
    $offset += strlen($line);
}
unset($row, $line);
$rows = new DatasetRows($file, $index, $count);
$training = (new ReflectionClass(CandleTraining::class))->newInstanceWithoutConstructor();
$started = hrtime(true);

if ($mode === 'compact') {
    $tickers = (new ReflectionMethod(CandleTraining::class, 'autoLabelContext'))->invoke($training, $rows);
} else {
    $tickers = [];
    foreach ($rows as $row) {
        $tickers[] = [...$row['candle'], 'microtimestamp' => $row['microtimestamp'], 'decision_at_ms' => $row['decision_at_ms']];
    }
    unset($row);
    $training->candle_anatomy($tickers);
}

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

$counts = ['buy' => 0, 'hold' => 0, 'sell' => 0, 'unlabelled' => 0];
$hash = hash_init('sha256');
foreach ($tickers as $position => $ticker) {
    $action = $ticker['action'] ?? 'unlabelled';
    $counts[$action]++;
    hash_update($hash, $position.':'.$action."\n");
}

$result = [
    'mode' => $mode, 'rows' => $count, 'memory_limit' => ini_get('memory_limit'),
    'peak_bytes' => memory_get_peak_usage(true),
    'elapsed_seconds' => round((hrtime(true) - $started) / 1e9, 3),
    'counts' => $counts, 'actions_sha256' => hash_final($hash),
    'first_action' => $tickers[0]['action'] ?? null,
    'last_action' => $tickers[$count - 1]['action'] ?? null,
];
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
