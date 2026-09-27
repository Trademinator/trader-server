<?php

// Offline: no Laravel boot, database, credentials or exchange HTTP requests.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Domain\MarketData\CcxtAdapterInspector;
use App\Domain\MarketData\ExchangeMetadataBuilder;

$argument = $argv[1] ?? null;
if ($argc > 2) {
    throw new InvalidArgumentException('Usage: php scripts/build-exchange-metadata.php [--runtime|EXCHANGE_ID]');
}
if ($argument !== null && $argument !== '--runtime') {
    echo json_encode(CcxtAdapterInspector::inspect($argument), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit(0);
}

$project = dirname(__DIR__);
$path = $project.($argument === '--runtime' ? '/storage/app/private/ccxt-exchanges.json' : '/resources/data/ccxt-exchanges.json');
$lock = fopen($project.'/storage/framework/ccxt-metadata.lock', 'c');
if ($lock === false || ! flock($lock, LOCK_EX)) {
    throw new RuntimeException('Could not lock exchange metadata.');
}
try {
    $previous = ExchangeMetadataBuilder::read(is_file($path) ? $path : $project.'/resources/data/ccxt-exchanges.json');
    $metadata = (new ExchangeMetadataBuilder)->build($project);
    ExchangeMetadataBuilder::write($path, $metadata);
    $report = ExchangeMetadataBuilder::report($metadata, $previous);
    echo 'Inspected '.count($metadata['exchanges']).' CCXT adapters: '.json_encode($report['counts']).PHP_EOL;
    foreach (['added', 'removed', 'changed'] as $category) {
        if ($report[$category] !== []) {
            echo $category.': '.implode(', ', $report[$category]).PHP_EOL;
        }
    }
    if ($report['needs_review'] !== []) {
        echo 'Hidden pending review: '.implode(', ', $report['needs_review']).PHP_EOL;
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
