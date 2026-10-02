<?php

use App\Domain\Archive\MultipartPortableArchive;
use App\Jobs\ImportPortableArchivePart;
use App\Models\Ticker;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->portableRoot = storage_path('framework/testing/portable-'.bin2hex(random_bytes(4)));
    config([
        'archive.portable_root' => $this->portableRoot,
        'archive.portable_queue' => 'default',
        'archive.portable_part_max_rows' => 2,
        'archive.portable_part_max_uncompressed_bytes' => 1024 * 1024,
        'archive.portable_part_max_compressed_bytes' => 1024 * 1024,
        'archive.portable_line_max_bytes' => 64 * 1024,
        'archive.portable_max_parts' => 100,
        'archive.portable_retention_hours' => 24,
    ]);
    Queue::fake();
});

afterEach(function () {
    if (! isset($this->portableRoot) || ! is_dir($this->portableRoot)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->portableRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($this->portableRoot);
});

it('exports bounded gzip parts and waits for every verified part before importing', function () {
    $user = User::factory()->create();
    for ($i = 0; $i < 5; $i++) {
        Ticker::query()->create([
            'exchange' => 'kraken',
            'symbol' => 'BTC/USD',
            'period' => '1m',
            'microtimestamp' => 1_700_000_000_000 + $i * 60_000,
            'payload' => json_encode([
                'open' => '100.00000000', 'high' => '101.00000000', 'low' => '99.00000000',
                'close' => (string) (100 + $i).'.00000000', 'volume' => '1.00000000',
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    $portable = app(MultipartPortableArchive::class);
    $exportId = $portable->startExport($user);
    foreach ([1, 2, 3, 4] as $sequence) {
        $portable->exportPart($exportId, $sequence);
    }

    $export = DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $exportId)->first();
    $parts = DB::table('portable_archive_parts')->where('portable_archive_transfer_id', $exportId)->orderBy('sequence')->get();
    expect($export->status)->toBe('ready')
        ->and((int) $export->expected_parts)->toBe(3)
        ->and((int) $export->total_rows)->toBe(5)
        ->and($parts)->toHaveCount(3);
    foreach ($parts as $part) {
        expect(is_file($part->path))->toBeTrue()
            ->and((int) $part->row_count)->toBeLessThanOrEqual(2);
    }

    $manifest = new UploadedFile($export->manifest_path, 'manifest.json', 'application/json', null, true);
    $importId = $portable->startImport($user, $manifest, true);
    Ticker::query()->delete();

    $upload = function ($part) use ($portable, $user, $importId): void {
        $copy = tempnam(sys_get_temp_dir(), 'portable-part-');
        copy($part->path, $copy);
        $portable->receiveImportPart($user, $importId, new UploadedFile($copy, $part->file_name, 'application/gzip', null, true));
        $portable->verifyImportPart($importId, (int) $part->sequence);
    };

    $upload($parts[0]);
    expect(DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $importId)->value('status'))->toBe('verifying');
    Queue::assertNotPushed(ImportPortableArchivePart::class);
    expect(Ticker::query()->count())->toBe(0);

    $upload($parts[1]);
    $upload($parts[2]);
    expect(DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $importId)->value('status'))->toBe('validated');
    Queue::assertNotPushed(ImportPortableArchivePart::class);

    $portable->beginValidatedImport($user, $importId);
    expect(DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $importId)->value('status'))->toBe('importing');
    Queue::assertPushed(ImportPortableArchivePart::class, fn (ImportPortableArchivePart $job): bool => $job->transferId === $importId && $job->sequence === 1);

    foreach ([1, 2, 3] as $sequence) {
        $portable->importPart($importId, $sequence);
    }

    $import = DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $importId)->first();
    expect($import->status)->toBe('completed')
        ->and((int) $import->completed_parts)->toBe(3)
        ->and(Ticker::query()->count())->toBe(5);
});
