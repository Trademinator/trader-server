<?php

use App\Domain\Archive\ArchiveIntegrityException;
use App\Domain\Archive\PortableJson;
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
    $rows = array_values(iterator_to_array(
        $repo->streamHistory('kraken', 'BTC/USD', '1m', $first, $first + 60000),
        true
    ));
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

it('rejects manifest data_file values that are not leaf gzip filenames', function (string $dataFile) {
    $repo = app(TickerRepository::class);
    $first = gmmktime(0, 0, 0, 4, 2, 2026) * 1000;
    $repo->saveTickers('kraken', 'BTC/USD', '1m', [m43Candle($first, '100.00000000')]);
    $result = app(TickerArchive::class)->archiveMonth('kraken', 'BTC/USD', '1m', 2026, 4);
    $manifestPath = $this->archiveRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $result['manifest']);
    $manifest = PortableJson::decode(trim((string) file_get_contents($manifestPath)));
    $manifest['data_file'] = $dataFile;
    file_put_contents($manifestPath, PortableJson::encode($manifest)."\n");

    expect(fn () => app(TickerArchive::class)->verifyManifest($result['manifest']))
        ->toThrow(ArchiveIntegrityException::class, 'Archive manifest data file is unsafe.');
})->with([
    'parent traversal' => '../../outside.jsonl.gz',
    'nested path' => 'nested/data.jsonl.gz',
    'backslash path' => 'nested\\data.jsonl.gz',
    'bare parent' => '..',
    'nul byte' => "data\0.jsonl.gz",
]);

it('rejects unsafe manifest paths before filesystem access', function (string $path) {
    expect(fn () => app(TickerArchive::class)->verifyManifest($path))
        ->toThrow(ArchiveIntegrityException::class, 'Unsafe archive path.');
})->with([
    'bare parent' => '..',
    'trailing parent' => 'tickers/..',
    'dot segment' => 'tickers/./file.manifest.json',
    'nul byte' => "tickers/bad\0.manifest.json",
]);

it('rejects archive data symlinks that escape the configured root', function () {
    if (! function_exists('symlink')) {
        $this->markTestSkipped('Symlinks are unavailable on this platform.');
    }

    $repo = app(TickerRepository::class);
    $first = gmmktime(0, 0, 0, 5, 2, 2026) * 1000;
    $repo->saveTickers('kraken', 'BTC/USD', '1m', [m43Candle($first, '100.00000000')]);
    $result = app(TickerArchive::class)->archiveMonth('kraken', 'BTC/USD', '1m', 2026, 5);
    $dataPath = $this->archiveRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $result['data']);

    $outsideDir = dirname($this->archiveRoot).DIRECTORY_SEPARATOR.'archive-outside-'.bin2hex(random_bytes(4));
    mkdir($outsideDir, 0770, true);
    $outsidePath = $outsideDir.DIRECTORY_SEPARATOR.basename($dataPath);
    rename($dataPath, $outsidePath);
    if (! @symlink($outsidePath, $dataPath)) {
        rename($outsidePath, $dataPath);
        rmdir($outsideDir);
        $this->markTestSkipped('Unable to create a test symlink.');
    }

    try {
        expect(fn () => app(TickerArchive::class)->verifyManifest($result['manifest']))
            ->toThrow(ArchiveIntegrityException::class, 'Archive path escapes configured root.');
    } finally {
        @unlink($dataPath);
        if (is_file($outsidePath)) {
            rename($outsidePath, $dataPath);
        }
        @rmdir($outsideDir);
    }
});
