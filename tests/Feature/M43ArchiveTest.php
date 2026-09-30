<?php

use App\Domain\Archive\ArchiveIntegrityException;
use App\Domain\Archive\PortablePackage;
use App\Domain\Archive\TickerArchive;
use App\Models\Ticker;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->archiveRoot = storage_path('framework/testing/archive-'.bin2hex(random_bytes(4)));
    config(['archive.enabled' => true, 'archive.root' => $this->archiveRoot, 'archive.gzip_level' => 1]);
});

afterEach(function () {
    if (! isset($this->archiveRoot) || ! is_dir($this->archiveRoot)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->archiveRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($this->archiveRoot);
});

function m43Candle(int $timestamp, string $close): array
{
    return ['human_date' => gmdate('YmdHis', intdiv($timestamp, 1000)), 'microtimestamp' => $timestamp,
        'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close, 'volume' => '1.00000000'];
}

it('creates verified monthly shards and can serve cold ticker history after hot rows are absent', function () {
    $repo = app(TickerRepository::class);
    $first = gmmktime(0, 0, 0, 1, 2, 2026) * 1000;
    $repo->saveTickers('kraken', 'BTC/USD', '1m', [m43Candle($first, '100.00000000'), m43Candle($first + 60000, '101.00000000')]);

    $result = app(TickerArchive::class)->archiveMonth('kraken', 'BTC/USD', '1m', 2026, 1);
    expect($result['status'])->toBe('verified')
        ->and(DB::table('archive_catalog')->where('verification_state', 'verified')->count())->toBe(1);

    Ticker::query()->delete(); // Simulate a future, separately-approved prune.
    $rows = $repo->fetchFromDB('kraken', 'BTC/USD', '1m', $first, $first + 60000);
    expect($rows)->toHaveCount(2)->and($rows[1]['close'])->toBe('101.00000000');
});

it('restores idempotently and rejects a conflicting hot duplicate', function () {
    $repo = app(TickerRepository::class);
    $first = gmmktime(0, 0, 0, 2, 2, 2026) * 1000;
    $repo->saveTickers('kraken', 'ETH/USD', '1m', [m43Candle($first, '200.00000000')]);
    $result = app(TickerArchive::class)->archiveMonth('kraken', 'ETH/USD', '1m', 2026, 2);
    Ticker::query()->delete();

    $restored = app(TickerArchive::class)->restoreManifest($result['manifest']);
    expect($restored['inserted'])->toBe(1);
    $again = app(TickerArchive::class)->restoreManifest($result['manifest']);
    expect($again['identical'])->toBe(1);

    $ticker = Ticker::query()->firstOrFail();
    $payload = json_decode($ticker->payload, true, flags: JSON_THROW_ON_ERROR);
    $payload['close'] = '999.00000000';
    $ticker->forceFill(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)])->save();
    expect(fn () => app(TickerArchive::class)->restoreManifest($result['manifest']))
        ->toThrow(ArchiveIntegrityException::class, 'Restore conflict');
});

it('validates a portable package before mutation and imports identical data idempotently', function () {
    $repo = app(TickerRepository::class);
    $first = gmmktime(0, 0, 0, 3, 2, 2026) * 1000;
    $repo->saveTickers('kraken', 'SOL/USD', '1m', [m43Candle($first, '50.00000000')]);
    $path = $this->archiveRoot.'/portable.jsonl.gz';
    app(PortablePackage::class)->export($path, ['tickers']);
    Ticker::query()->delete();

    $validated = app(PortablePackage::class)->import($path, true);
    expect($validated['validated_only'])->toBeTrue()->and(Ticker::query()->count())->toBe(0);
    app(PortablePackage::class)->import($path);
    expect(Ticker::query()->count())->toBe(1);
    $again = app(PortablePackage::class)->import($path);
    expect($again['identical'])->toBe(1)->and(Ticker::query()->count())->toBe(1);
});
