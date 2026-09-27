<?php

use App\Domain\MarketData\CcxtAdapterInspector;
use App\Domain\MarketData\ExchangeMetadataBuilder;
use ccxt\Exchange;

it('requires a matching reviewed source before accepting any access classification', function () {
    $entry = ['source_fingerprint' => 'current', 'fetchOHLCV' => true];
    $review = ['source_fingerprint' => 'current', 'markets' => 'public', 'candles' => 'public', 'evidence' => ['method'], 'note' => 'Reviewed'];
    expect(ExchangeMetadataBuilder::classify($entry, $review)['state'])->toBe('public')
        ->and(ExchangeMetadataBuilder::classify($entry, null)['reason'])->toBe('not_reviewed')
        ->and(ExchangeMetadataBuilder::classify($entry, [...$review, 'source_fingerprint' => 'old'])['reason'])->toBe('source_changed')
        ->and(ExchangeMetadataBuilder::classify($entry, [...$review, 'evidence' => []])['reason'])->toBe('invalid_review');
    foreach (['markets', 'candles'] as $step) {
        expect(ExchangeMetadataBuilder::classify($entry, [...$review, $step => 'authentication_required'])['state'])->toBe('authentication_required')
            ->and(ExchangeMetadataBuilder::classify($entry, [...$review, $step => 'unknown'])['state'])->toBe('unknown');
    }
    expect(ExchangeMetadataBuilder::classify([...$entry, 'fetchOHLCV' => false], $review)['reason'])->toBe('ohlcv_not_supported')
        ->and(ExchangeMetadataBuilder::classify([...$entry, 'fetchOHLCV' => 'emulated'], $review)['state'])->toBe('unknown');
});

it('detects edits to adapter parents and common code without loading classes', function () {
    $root = sys_get_temp_dir().'/ccxt-source-'.bin2hex(random_bytes(8));
    mkdir($root.'/php/abstract', 0755, true);
    $files = ['php/child.php' => 'child', 'php/abstract/child.php' => 'endpoints', 'php/parent.php' => 'parent', 'php/Exchange.php' => 'base'];
    $hashes = [];
    foreach ($files as $file => $contents) {
        file_put_contents($root.'/'.$file, $contents);
        $files[$file] = hash('sha256', $contents);
    }
    try {
        expect(CcxtAdapterInspector::matches($files, $root, $hashes))->toBeTrue();
        foreach (['php/parent.php', 'php/Exchange.php'] as $file) {
            $original = file_get_contents($root.'/'.$file);
            file_put_contents($root.'/'.$file, 'changed');
            $hashes = [];
            expect(CcxtAdapterInspector::matches($files, $root, $hashes))->toBeFalse();
            file_put_contents($root.'/'.$file, $original);
        }
        unlink($root.'/php/child.php');
        $hashes = [];
        expect(CcxtAdapterInspector::matches($files, $root, $hashes))->toBeFalse();
    } finally {
        foreach (array_keys($files) as $file) {
            if (is_file($root.'/'.$file)) {
                unlink($root.'/'.$file);
            }
        }
        rmdir($root.'/php/abstract');
        rmdir($root.'/php');
        rmdir($root);
    }
});

it('reports additions removals changes and review requirements separately from unsupported OHLCV', function () {
    $public = ['source_fingerprint' => 'one', 'access' => ['state' => 'public', 'reason' => 'source_reviewed']];
    $changed = ['source_fingerprint' => 'two', 'access' => ExchangeMetadataBuilder::unknown('source_changed')];
    $unsupported = ['access' => ExchangeMetadataBuilder::unknown('ohlcv_not_supported')];
    $report = ExchangeMetadataBuilder::report(['ccxt_version' => 'test', 'exchanges' => ['kept' => $public, 'changed' => $changed, 'added' => $changed, 'unsupported' => $unsupported]],
        ['exchanges' => ['kept' => $public, 'changed' => $public, 'removed' => $public, 'unsupported' => $unsupported]]);
    expect($report['added'])->toBe(['added'])->and($report['removed'])->toBe(['removed'])
        ->and($report['changed'])->toBe(['changed'])->and($report['needs_review'])->toBe(['changed', 'added'])
        ->and($report['counts'])->toBe(['public' => 1, 'authentication_required' => 0, 'unknown' => 3]);
});

it('publishes an explicit unknown result when an adapter inspection fails', function () {
    $project = sys_get_temp_dir().'/ccxt-worker-'.bin2hex(random_bytes(8));
    mkdir($project.'/scripts', 0755, true);
    file_put_contents($project.'/scripts/build-exchange-metadata.php', '<?php exit(1);');
    $ids = Exchange::$exchanges;
    try {
        Exchange::$exchanges = ['failed_adapter'];
        $snapshot = (new ExchangeMetadataBuilder)->build($project);
        expect($snapshot['exchanges']['failed_adapter']['access'])->toBe(ExchangeMetadataBuilder::unknown('inspection_failed'))
            ->and($snapshot['exchanges']['failed_adapter']['spot'])->toBeFalse();
    } finally {
        Exchange::$exchanges = $ids;
        unlink($project.'/scripts/build-exchange-metadata.php');
        rmdir($project.'/scripts');
        rmdir($project);
    }
});
