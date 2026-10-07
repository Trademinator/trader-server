<?php

namespace App\Domain\Archive;

use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\TickerHistoryCache;
use App\Jobs\ExportPortableArchivePart;
use App\Jobs\ImportPortableArchivePart;
use App\Jobs\VerifyPortableArchivePart;
use App\Models\Ticker;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class MultipartPortableArchive
{
    public function startExport(User $user): string
    {
        $existing = DB::table('portable_archive_transfers')
            ->where('user_id', $user->user_id)->where('direction', 'export')
            ->whereIn('status', ['pending', 'running'])->orderByDesc('created_at')->value('portable_archive_transfer_id');
        if (is_string($existing)) {
            return $existing;
        }

        $id = (string) Str::uuid7();
        $endCursor = Ticker::query()->max('ticker_id');
        DB::table('portable_archive_transfers')->insert([
            'portable_archive_transfer_id' => $id,
            'user_id' => $user->user_id,
            'direction' => 'export',
            'status' => 'pending',
            'validate_only' => false,
            'source_export_id' => $id,
            'end_cursor' => is_string($endCursor) ? $endCursor : null,
            'expected_parts' => 0,
            'completed_parts' => 0,
            'verified_parts' => 0,
            'total_rows' => 0,
            'inserted_rows' => 0,
            'identical_rows' => 0,
            'expires_at' => now()->addHours((int) config('archive.portable_retention_hours')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ExportPortableArchivePart::dispatch($id, 1);

        return $id;
    }

    public function exportPart(string $transferId, int $sequence): void
    {
        $transfer = $this->transfer($transferId, 'export');
        if (in_array($transfer->status, ['ready', 'failed'], true)) {
            return;
        }
        $existing = DB::table('portable_archive_parts')
            ->where('portable_archive_transfer_id', $transferId)->where('sequence', $sequence)->first();
        if ($existing !== null) {
            if ($transfer->status !== 'ready') {
                ExportPortableArchivePart::dispatch($transferId, $sequence + 1);
            }

            return;
        }
        if ($sequence > (int) config('archive.portable_max_parts')) {
            if (is_string($transfer->cursor) && is_string($transfer->end_cursor) && $transfer->cursor === $transfer->end_cursor) {
                $this->finalizeExport($transferId);

                return;
            }
            throw new ArchiveIntegrityException('Portable export exceeded the configured maximum number of parts.');
        }
        if ($sequence !== (int) $transfer->completed_parts + 1) {
            throw new ArchiveIntegrityException('Portable export part sequence is out of order.');
        }

        if (! is_string($transfer->end_cursor) || $transfer->end_cursor === '') {
            $this->finalizeExport($transferId);

            return;
        }

        $directory = $this->transferDirectory('export', $transferId);
        $this->ensureDirectory($directory);
        $fileName = sprintf('part-%06d.jsonl.gz', $sequence);
        $finalPath = $directory.DIRECTORY_SEPARATOR.$fileName;
        $tmpPath = $finalPath.'.tmp.'.bin2hex(random_bytes(6));
        $gz = gzopen($tmpPath, 'wb'.(int) config('archive.gzip_level'));
        if ($gz === false) {
            throw new ArchiveIntegrityException('Unable to create portable export part.');
        }

        $rows = 0;
        $uncompressed = 0;
        $firstKey = $lastKey = $lastTickerId = null;
        try {
            $header = [
                'record_type' => 'part',
                'portable_format' => PortableJson::FORMAT,
                'format_version' => (int) config('archive.portable_format_version'),
                'export_id' => $transferId,
                'dataset' => 'tickers',
                'sequence' => $sequence,
                'secrets_included' => false,
            ];
            $headerLine = PortableJson::encode($header)."\n";
            $this->assertLineSize($headerLine);
            $this->writeLine($gz, $headerLine);
            $uncompressed += strlen($headerLine);

            $query = Ticker::query()->orderBy('ticker_id');
            if (is_string($transfer->cursor) && $transfer->cursor !== '') {
                $query->where('ticker_id', '>', $transfer->cursor);
            }
            $query->where('ticker_id', '<=', $transfer->end_cursor);
            foreach ($query->cursor() as $ticker) {
                if ($rows >= (int) config('archive.portable_part_max_rows')) {
                    break;
                }
                $data = $this->tickerData($ticker);
                $line = PortableJson::encode(['record_type' => 'row', 'dataset' => 'tickers', 'data' => $data])."\n";
                $this->assertLineSize($line);
                if ($uncompressed + strlen($line) > (int) config('archive.portable_part_max_uncompressed_bytes')) {
                    if ($rows === 0) {
                        throw new ArchiveIntegrityException('A single portable ticker row exceeds the configured part size.');
                    }
                    break;
                }
                $this->writeLine($gz, $line);
                $uncompressed += strlen($line);
                $key = $this->logicalKey($data);
                $firstKey ??= $key;
                $lastKey = $key;
                $lastTickerId = (string) $ticker->ticker_id;
                $rows++;
            }
        } catch (Throwable $error) {
            gzclose($gz);
            @unlink($tmpPath);
            throw $error;
        }
        gzclose($gz);

        if ($rows === 0) {
            @unlink($tmpPath);
            $this->finalizeExport($transferId);

            return;
        }

        $compressed = filesize($tmpPath);
        $sha = hash_file('sha256', $tmpPath);
        if (! is_int($compressed) || ! is_string($sha)) {
            @unlink($tmpPath);
            throw new ArchiveIntegrityException('Unable to inspect completed portable export part.');
        }
        if ($compressed > (int) config('archive.portable_part_max_compressed_bytes')) {
            @unlink($tmpPath);
            throw new ArchiveIntegrityException('Portable export part exceeds the configured compressed size limit.');
        }
        if (is_file($finalPath)) {
            @unlink($finalPath);
        }
        if (! rename($tmpPath, $finalPath)) {
            @unlink($tmpPath);
            throw new ArchiveIntegrityException('Unable to publish portable export part.');
        }

        DB::transaction(function () use ($transferId, $sequence, $fileName, $finalPath, $sha, $compressed, $uncompressed, $rows, $firstKey, $lastKey, $lastTickerId): void {
            $locked = DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)->lockForUpdate()->first();
            if ($locked === null) {
                throw new ArchiveIntegrityException('Portable export no longer exists.');
            }
            DB::table('portable_archive_parts')->insert([
                'portable_archive_part_id' => (string) Str::uuid7(),
                'portable_archive_transfer_id' => $transferId,
                'sequence' => $sequence,
                'file_name' => $fileName,
                'status' => 'ready',
                'path' => $finalPath,
                'sha256' => $sha,
                'compressed_size' => $compressed,
                'uncompressed_size' => $uncompressed,
                'row_count' => $rows,
                'first_key' => $firstKey,
                'last_key' => $lastKey,
                'inserted_rows' => 0,
                'identical_rows' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)->update([
                'status' => 'running',
                'cursor' => $lastTickerId,
                'completed_parts' => $sequence,
                'total_rows' => (int) $locked->total_rows + $rows,
                'error' => null,
                'expires_at' => now()->addHours((int) config('archive.portable_retention_hours')),
                'updated_at' => now(),
            ]);
        });

        ExportPortableArchivePart::dispatch($transferId, $sequence + 1);
    }

    public function startImport(User $user, UploadedFile $manifestFile, bool $validateOnly): string
    {
        $path = $manifestFile->getRealPath();
        if (! is_string($path) || ! is_file($path)) {
            throw new ArchiveIntegrityException('Portable manifest upload is unavailable.');
        }
        $raw = file_get_contents($path);
        if (! is_string($raw)) {
            throw new ArchiveIntegrityException('Unable to read portable manifest.');
        }
        $manifest = PortableJson::decode(trim($raw));
        $this->validateSetManifest($manifest);

        $id = (string) Str::uuid7();
        $directory = $this->transferDirectory('import', $id);
        $this->ensureDirectory($directory);
        $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        if (file_put_contents($manifestPath, PortableJson::encode($manifest)."\n", LOCK_EX) === false) {
            $this->removeDirectory($directory);
            throw new ArchiveIntegrityException('Unable to store portable import manifest.');
        }

        try {
            DB::transaction(function () use ($id, $user, $manifest, $manifestPath, $validateOnly): void {
                DB::table('portable_archive_transfers')->insert([
                    'portable_archive_transfer_id' => $id,
                    'user_id' => $user->user_id,
                    'direction' => 'import',
                    'status' => ((int) $manifest['part_count'] === 0) ? ($validateOnly ? 'validated' : 'completed') : 'uploading',
                    'validate_only' => $validateOnly,
                    'source_export_id' => $manifest['export_id'],
                    'expected_parts' => $manifest['part_count'],
                    'completed_parts' => 0,
                    'verified_parts' => 0,
                    'total_rows' => $manifest['total_rows'],
                    'inserted_rows' => 0,
                    'identical_rows' => 0,
                    'manifest_path' => $manifestPath,
                    'expires_at' => now()->addHours((int) config('archive.portable_retention_hours')),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                foreach ($manifest['parts'] as $part) {
                    DB::table('portable_archive_parts')->insert([
                        'portable_archive_part_id' => (string) Str::uuid7(),
                        'portable_archive_transfer_id' => $id,
                        'sequence' => $part['sequence'],
                        'file_name' => $part['file'],
                        'status' => 'pending',
                        'path' => null,
                        'sha256' => $part['sha256'],
                        'compressed_size' => $part['compressed_size'],
                        'uncompressed_size' => $part['uncompressed_size'],
                        'row_count' => $part['row_count'],
                        'first_key' => $part['first_key'],
                        'last_key' => $part['last_key'],
                        'inserted_rows' => 0,
                        'identical_rows' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
        } catch (Throwable $error) {
            $this->removeDirectory($directory);
            throw $error;
        }

        return $id;
    }

    public function receiveImportPart(User $user, string $transferId, UploadedFile $file): void
    {
        $transfer = $this->transferForUser($user, $transferId, 'import');
        if (! in_array($transfer->status, ['uploading', 'verifying'], true)) {
            throw new ArchiveIntegrityException('Portable import is not accepting parts in its current state.');
        }
        $fileName = $file->getClientOriginalName();
        if (! is_string($fileName) || ! preg_match('/^part-\d{6}\.jsonl\.gz$/D', $fileName)) {
            throw new ArchiveIntegrityException('Portable part filename is invalid.');
        }
        $part = DB::table('portable_archive_parts')->where('portable_archive_transfer_id', $transferId)->where('file_name', $fileName)->first();
        if ($part === null) {
            throw new ArchiveIntegrityException('This part is not listed in the uploaded manifest.');
        }
        if (in_array($part->status, ['verified', 'imported'], true)) {
            throw new ArchiveIntegrityException('This portable part has already been verified.');
        }
        $size = $file->getSize();
        if (! is_int($size) || $size !== (int) $part->compressed_size) {
            throw new ArchiveIntegrityException('Portable part compressed size does not match its manifest.');
        }

        $directory = $this->transferDirectory('import', $transferId);
        $this->ensureDirectory($directory);
        $target = $directory.DIRECTORY_SEPARATOR.$fileName;
        if (is_file($target)) {
            @unlink($target);
        }
        $file->move($directory, $fileName);
        DB::table('portable_archive_parts')->where('portable_archive_part_id', $part->portable_archive_part_id)->update([
            'status' => 'uploaded', 'path' => $target, 'error' => null, 'updated_at' => now(),
        ]);
        DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)->update([
            'status' => 'verifying', 'error' => null,
            'expires_at' => now()->addHours((int) config('archive.portable_retention_hours')), 'updated_at' => now(),
        ]);
        VerifyPortableArchivePart::dispatch($transferId, (int) $part->sequence);
    }

    public function verifyImportPart(string $transferId, int $sequence): void
    {
        $transfer = $this->transfer($transferId, 'import');
        $part = $this->part($transferId, $sequence);
        if (in_array($part->status, ['verified', 'imported'], true)) {
            $this->advanceVerifiedImport($transferId);

            return;
        }
        if (! is_string($part->path) || ! is_file($part->path)) {
            throw new ArchiveIntegrityException('Portable import part has not been uploaded.');
        }
        $path = $this->safeExistingTransferFile('import', $transferId, $part->path);
        $this->assertFileDigest($part, $path);
        $metadata = $this->readPart($path, $transfer, $part);
        $this->assertPartMetadata($part, $metadata);

        DB::table('portable_archive_parts')->where('portable_archive_part_id', $part->portable_archive_part_id)->update([
            'status' => 'verified', 'error' => null, 'updated_at' => now(),
        ]);
        $this->advanceVerifiedImport($transferId);
    }

    public function importPart(string $transferId, int $sequence): void
    {
        $transfer = $this->transfer($transferId, 'import');
        $part = $this->part($transferId, $sequence);
        if ($part->status === 'imported') {
            $this->dispatchNextImportPart($transferId, $sequence, (int) $transfer->expected_parts);

            return;
        }
        if ($part->status !== 'verified') {
            throw new ArchiveIntegrityException('Portable part must verify before it can be imported.');
        }
        $notVerified = DB::table('portable_archive_parts')->where('portable_archive_transfer_id', $transferId)
            ->whereNotIn('status', ['verified', 'imported'])->exists();
        if ($notVerified) {
            throw new ArchiveIntegrityException('All portable parts must verify before import begins.');
        }
        $path = $this->safeExistingTransferFile('import', $transferId, $part->path);
        $this->assertFileDigest($part, $path);

        $pending = [];
        $changed = [];
        $inserted = $identical = 0;
        try {
            $metadata = $this->readPart(
                $path,
                $transfer,
                $part,
                function (array $data) use (&$pending, &$changed, &$inserted, &$identical): void {
                    $existing = Ticker::query()->where('exchange', $data['exchange'])->where('symbol', $data['symbol'])
                        ->where('period', $data['period'])->where('microtimestamp', $data['microtimestamp'])->first();
                    if ($existing !== null) {
                        $hot = json_decode($existing->payload, true, flags: JSON_THROW_ON_ERROR);
                        if (PortableJson::encode($hot) !== PortableJson::encode($data['payload'])) {
                            throw new ArchiveIntegrityException('Portable import conflict at '.$this->logicalKey($data).'.');
                        }
                        $identical++;

                        return;
                    }
                    $idCollision = Ticker::query()->whereKey($data['ticker_id'])->exists();
                    if ($idCollision) {
                        throw new ArchiveIntegrityException('Portable ticker UUID conflicts with a different stored row.');
                    }
                    $changed[$data['exchange']."\0".$data['symbol']."\0".$data['period']] = [
                        $data['exchange'], $data['symbol'], $data['period'],
                    ];
                    $pending[] = [
                        'ticker_id' => $data['ticker_id'],
                        'exchange' => $data['exchange'],
                        'symbol' => $data['symbol'],
                        'period' => $data['period'],
                        'microtimestamp' => $data['microtimestamp'],
                        'payload' => PortableJson::encode($data['payload']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if (count($pending) >= 250) {
                        DB::table('tickers')->insert($pending);
                        $inserted += count($pending);
                        $pending = [];
                    }
                }
            );
            if ($pending !== []) {
                DB::table('tickers')->insert($pending);
                $inserted += count($pending);
            }
        } finally {
            foreach ($changed as [$exchange, $symbol, $period]) {
                app(TickerHistoryCache::class)->invalidate($exchange, $symbol, $period);
            }
        }
        $this->assertPartMetadata($part, $metadata);

        DB::transaction(function () use ($transferId, $part, $inserted, $identical): void {
            DB::table('portable_archive_parts')->where('portable_archive_part_id', $part->portable_archive_part_id)->update([
                'status' => 'imported', 'inserted_rows' => $inserted, 'identical_rows' => $identical, 'error' => null, 'updated_at' => now(),
            ]);
            $locked = DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)->lockForUpdate()->first();
            DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)->update([
                'completed_parts' => (int) $locked->completed_parts + 1,
                'inserted_rows' => (int) $locked->inserted_rows + $inserted,
                'identical_rows' => (int) $locked->identical_rows + $identical,
                'error' => null,
                'expires_at' => now()->addHours((int) config('archive.portable_retention_hours')),
                'updated_at' => now(),
            ]);
        });
        $this->dispatchNextImportPart($transferId, $sequence, (int) $transfer->expected_parts);
    }

    public function beginValidatedImport(User $user, string $transferId): void
    {
        $transfer = $this->transferForUser($user, $transferId, 'import');
        if ($transfer->status !== 'validated') {
            throw new ArchiveIntegrityException('Portable import set is not fully validated.');
        }
        $pending = DB::table('portable_archive_parts')->where('portable_archive_transfer_id', $transferId)
            ->where('status', '!=', 'verified')->exists();
        if ($pending) {
            throw new ArchiveIntegrityException('Every portable part must verify before import begins.');
        }
        if ((int) $transfer->expected_parts === 0) {
            DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)->update([
                'validate_only' => false, 'status' => 'completed', 'updated_at' => now(),
            ]);

            return;
        }
        DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)->update([
            'validate_only' => false,
            'status' => 'importing',
            'expires_at' => now()->addHours((int) config('archive.portable_retention_hours')),
            'updated_at' => now(),
        ]);
        ImportPortableArchivePart::dispatch($transferId, 1);
    }

    public function recentTransfers(User $user): Collection
    {
        $transfers = DB::table('portable_archive_transfers')->where('user_id', $user->user_id)
            ->orderByDesc('created_at')->limit(12)->get();
        foreach ($transfers as $transfer) {
            $transfer->parts = DB::table('portable_archive_parts')
                ->where('portable_archive_transfer_id', $transfer->portable_archive_transfer_id)->orderBy('sequence')->get();
        }

        return $transfers;
    }

    public function manifestDownloadPath(User $user, string $transferId): string
    {
        $transfer = $this->transferForUser($user, $transferId, 'export');
        if ($transfer->status !== 'ready' || ! is_string($transfer->manifest_path) || ! is_file($transfer->manifest_path)) {
            throw new ArchiveIntegrityException('Portable export manifest is not ready.');
        }

        return $this->safeExistingTransferFile('export', $transferId, $transfer->manifest_path);
    }

    public function partDownloadPath(User $user, string $transferId, int $sequence): string
    {
        $transfer = $this->transferForUser($user, $transferId, 'export');
        if ($transfer->status !== 'ready') {
            throw new ArchiveIntegrityException('Portable export is not ready.');
        }
        $part = $this->part($transferId, $sequence);
        if ($part->status !== 'ready' || ! is_string($part->path) || ! is_file($part->path)) {
            throw new ArchiveIntegrityException('Portable export part is unavailable.');
        }

        return $this->safeExistingTransferFile('export', $transferId, $part->path);
    }

    public function failTransfer(string $transferId, Throwable $error): void
    {
        DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)->update([
            'status' => 'failed', 'error' => mb_substr($error->getMessage(), 0, 1000), 'updated_at' => now(),
        ]);
    }

    public function failImportPart(string $transferId, int $sequence, Throwable $error): void
    {
        DB::table('portable_archive_parts')->where('portable_archive_transfer_id', $transferId)->where('sequence', $sequence)->update([
            'status' => 'failed', 'error' => mb_substr($error->getMessage(), 0, 1000), 'updated_at' => now(),
        ]);
        DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)->update([
            'status' => 'uploading', 'error' => 'A part failed verification and may be uploaded again.', 'updated_at' => now(),
        ]);
    }

    public function pruneExpired(): int
    {
        $pruned = 0;
        DB::table('portable_archive_transfers')->where('expires_at', '<=', now())
            ->chunkById(100, function (Collection $rows) use (&$pruned): void {
                foreach ($rows as $row) {
                    $this->removeDirectory($this->transferDirectory($row->direction, $row->portable_archive_transfer_id));
                    $pruned += DB::table('portable_archive_transfers')
                        ->where('portable_archive_transfer_id', $row->portable_archive_transfer_id)->delete();
                }
            }, 'portable_archive_transfer_id');

        return $pruned;
    }

    private function finalizeExport(string $transferId): void
    {
        $transfer = $this->transfer($transferId, 'export');
        $parts = DB::table('portable_archive_parts')->where('portable_archive_transfer_id', $transferId)->orderBy('sequence')->get();
        $manifest = [
            'portable_format' => PortableJson::FORMAT,
            'format_version' => (int) config('archive.portable_format_version'),
            'export_id' => $transferId,
            'datasets' => ['tickers'],
            'part_count' => $parts->count(),
            'total_rows' => (int) $transfer->total_rows,
            'secrets_included' => false,
            'created_at' => now('UTC')->toIso8601String(),
            'parts' => $parts->map(fn ($part): array => [
                'sequence' => (int) $part->sequence,
                'file' => $part->file_name,
                'sha256' => $part->sha256,
                'compressed_size' => (int) $part->compressed_size,
                'uncompressed_size' => (int) $part->uncompressed_size,
                'row_count' => (int) $part->row_count,
                'first_key' => $part->first_key,
                'last_key' => $part->last_key,
            ])->all(),
        ];
        $directory = $this->transferDirectory('export', $transferId);
        $this->ensureDirectory($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(6));
        if (file_put_contents($tmp, PortableJson::encode($manifest)."\n", LOCK_EX) === false || ! rename($tmp, $path)) {
            @unlink($tmp);
            throw new ArchiveIntegrityException('Unable to publish portable export manifest.');
        }
        DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)->update([
            'status' => 'ready', 'expected_parts' => $parts->count(), 'manifest_path' => $path, 'error' => null,
            'expires_at' => now()->addHours((int) config('archive.portable_retention_hours')), 'updated_at' => now(),
        ]);
    }

    private function validateSetManifest(array $manifest): void
    {
        if (($manifest['portable_format'] ?? null) !== PortableJson::FORMAT
            || ($manifest['format_version'] ?? null) !== (int) config('archive.portable_format_version')
            || ! Str::isUuid((string) ($manifest['export_id'] ?? ''))
            || ($manifest['datasets'] ?? null) !== ['tickers']
            || ($manifest['secrets_included'] ?? true) !== false
            || ! is_int($manifest['part_count'] ?? null)
            || $manifest['part_count'] < 0
            || $manifest['part_count'] > (int) config('archive.portable_max_parts')
            || ! is_int($manifest['total_rows'] ?? null)
            || $manifest['total_rows'] < 0
            || ! is_array($manifest['parts'] ?? null)
            || ! array_is_list($manifest['parts'])
            || count($manifest['parts']) !== $manifest['part_count']) {
            throw new ArchiveIntegrityException('Unsupported or unsafe multipart portable manifest.');
        }
        $sum = 0;
        foreach ($manifest['parts'] as $index => $part) {
            $sequence = $index + 1;
            if (! is_array($part)
                || ($part['sequence'] ?? null) !== $sequence
                || ($part['file'] ?? null) !== sprintf('part-%06d.jsonl.gz', $sequence)
                || ! is_string($part['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $part['sha256'])
                || ! is_int($part['compressed_size'] ?? null) || $part['compressed_size'] < 1
                || $part['compressed_size'] > (int) config('archive.portable_part_max_compressed_bytes')
                || ! is_int($part['uncompressed_size'] ?? null) || $part['uncompressed_size'] < 1
                || $part['uncompressed_size'] > (int) config('archive.portable_part_max_uncompressed_bytes')
                || ! is_int($part['row_count'] ?? null) || $part['row_count'] < 1
                || $part['row_count'] > (int) config('archive.portable_part_max_rows')
                || ! is_string($part['first_key'] ?? null) || $part['first_key'] === '' || mb_strlen($part['first_key']) > 255
                || ! is_string($part['last_key'] ?? null) || $part['last_key'] === '' || mb_strlen($part['last_key']) > 255) {
                throw new ArchiveIntegrityException('Multipart portable manifest contains invalid part metadata.');
            }
            $sum += $part['row_count'];
        }
        if ($sum !== $manifest['total_rows']) {
            throw new ArchiveIntegrityException('Multipart portable manifest row totals do not match.');
        }
    }

    private function readPart(string $path, object $transfer, object $part, ?callable $consumer = null): array
    {
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new ArchiveIntegrityException('Unable to open multipart portable part.');
        }
        $bytes = $rows = $lineNo = 0;
        $first = $last = null;
        try {
            while (! gzeof($gz)) {
                $line = gzgets($gz, (int) config('archive.portable_line_max_bytes') + 2);
                if ($line === false) {
                    if (! gzeof($gz)) {
                        throw new ArchiveIntegrityException('Unable to read multipart portable part.');
                    }
                    break;
                }
                $lineNo++;
                if (strlen($line) > (int) config('archive.portable_line_max_bytes')) {
                    throw new ArchiveIntegrityException('Multipart portable line exceeds the configured limit.');
                }
                if (! str_ends_with($line, "\n")) {
                    throw new ArchiveIntegrityException('Multipart portable line is not newline terminated.');
                }
                $bytes += strlen($line);
                if ($bytes > (int) config('archive.portable_part_max_uncompressed_bytes')) {
                    throw new ArchiveIntegrityException('Multipart portable part expands beyond the configured limit.');
                }
                $record = PortableJson::decode(trim($line));
                if ($lineNo === 1) {
                    $this->validatePartHeader($record, $transfer, $part);

                    continue;
                }
                if (($record['record_type'] ?? null) !== 'row' || ($record['dataset'] ?? null) !== 'tickers' || ! is_array($record['data'] ?? null)) {
                    throw new ArchiveIntegrityException('Unsupported multipart portable row.');
                }
                $data = $record['data'];
                $this->validateTickerData($data);
                $key = $this->logicalKey($data);
                $first ??= $key;
                $last = $key;
                $rows++;
                if ($rows > (int) config('archive.portable_part_max_rows')) {
                    throw new ArchiveIntegrityException('Multipart portable part contains too many rows.');
                }
                if ($consumer !== null) {
                    $consumer($data);
                }
            }
        } finally {
            gzclose($gz);
        }
        if ($lineNo === 0) {
            throw new ArchiveIntegrityException('Multipart portable part is empty.');
        }

        return ['uncompressed_size' => $bytes, 'row_count' => $rows, 'first_key' => $first, 'last_key' => $last];
    }

    private function validatePartHeader(array $record, object $transfer, object $part): void
    {
        if (($record['record_type'] ?? null) !== 'part'
            || ($record['portable_format'] ?? null) !== PortableJson::FORMAT
            || ($record['format_version'] ?? null) !== (int) config('archive.portable_format_version')
            || ($record['export_id'] ?? null) !== $transfer->source_export_id
            || ($record['dataset'] ?? null) !== 'tickers'
            || ($record['sequence'] ?? null) !== (int) $part->sequence
            || ($record['secrets_included'] ?? true) !== false) {
            throw new ArchiveIntegrityException('Multipart portable part header does not match its manifest.');
        }
    }

    private function validateTickerData(array $data): void
    {
        if (! Str::isUuid((string) ($data['ticker_id'] ?? ''))
            || ! is_string($data['exchange'] ?? null) || $data['exchange'] === '' || mb_strlen($data['exchange']) > 32
            || ! is_string($data['symbol'] ?? null) || $data['symbol'] === '' || mb_strlen($data['symbol']) > 32
            || ! is_string($data['period'] ?? null) || ! in_array($data['period'], CandleTimeframe::SUPPORTED, true)
            || ! is_int($data['microtimestamp'] ?? null) || $data['microtimestamp'] < 0
            || ! is_array($data['payload'] ?? null)) {
            throw new ArchiveIntegrityException('Malformed ticker row in multipart portable archive.');
        }
        foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
            if (! is_string($data['payload'][$field] ?? null) && ! is_int($data['payload'][$field] ?? null)) {
                throw new ArchiveIntegrityException('Portable financial values must remain exact decimal strings or integers.');
            }
        }
    }

    private function tickerData(Ticker $ticker): array
    {
        return [
            'ticker_id' => (string) $ticker->ticker_id,
            'exchange' => (string) $ticker->exchange,
            'symbol' => (string) $ticker->symbol,
            'period' => (string) $ticker->period,
            'microtimestamp' => (int) $ticker->microtimestamp,
            'payload' => json_decode($ticker->payload, true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    private function assertFileDigest(object $part, string $path): void
    {
        $size = filesize($path);
        $sha = hash_file('sha256', $path);
        if (! is_int($size) || $size !== (int) $part->compressed_size || ! is_string($sha) || ! hash_equals($part->sha256, $sha)) {
            throw new ArchiveIntegrityException('Portable part checksum or compressed size does not match its manifest.');
        }
    }

    private function assertPartMetadata(object $part, array $metadata): void
    {
        if ($metadata['uncompressed_size'] !== (int) $part->uncompressed_size
            || $metadata['row_count'] !== (int) $part->row_count
            || $metadata['first_key'] !== $part->first_key
            || $metadata['last_key'] !== $part->last_key) {
            throw new ArchiveIntegrityException('Portable part contents do not match its manifest metadata.');
        }
    }

    private function advanceVerifiedImport(string $transferId): void
    {
        $transfer = $this->transfer($transferId, 'import');
        $verified = DB::table('portable_archive_parts')->where('portable_archive_transfer_id', $transferId)
            ->whereIn('status', ['verified', 'imported'])->count();
        if ($verified !== (int) $transfer->expected_parts) {
            DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)->update([
                'verified_parts' => $verified, 'status' => 'verifying', 'error' => null,
                'expires_at' => now()->addHours((int) config('archive.portable_retention_hours')), 'updated_at' => now(),
            ]);

            return;
        }
        if ((bool) $transfer->validate_only) {
            DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)->update([
                'verified_parts' => $verified, 'status' => 'validated', 'error' => null,
                'expires_at' => now()->addHours((int) config('archive.portable_retention_hours')), 'updated_at' => now(),
            ]);

            return;
        }
        $next = DB::table('portable_archive_parts')->where('portable_archive_transfer_id', $transferId)
            ->where('status', 'verified')->orderBy('sequence')->value('sequence');
        if ($next === null) {
            DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)->update([
                'verified_parts' => $verified, 'status' => 'completed', 'completed_parts' => $verified, 'error' => null,
                'expires_at' => now()->addHours((int) config('archive.portable_retention_hours')), 'updated_at' => now(),
            ]);

            return;
        }
        DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)->update([
            'verified_parts' => $verified, 'status' => 'importing', 'error' => null,
            'expires_at' => now()->addHours((int) config('archive.portable_retention_hours')), 'updated_at' => now(),
        ]);
        ImportPortableArchivePart::dispatch($transferId, (int) $next);
    }

    private function dispatchNextImportPart(string $transferId, int $sequence, int $expected): void
    {
        if ($sequence >= $expected) {
            DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)->update([
                'status' => 'completed', 'completed_parts' => $expected, 'error' => null,
                'expires_at' => now()->addHours((int) config('archive.portable_retention_hours')), 'updated_at' => now(),
            ]);

            return;
        }
        ImportPortableArchivePart::dispatch($transferId, $sequence + 1);
    }

    private function transfer(string $transferId, string $direction): object
    {
        $transfer = DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)
            ->where('direction', $direction)->first();
        if ($transfer === null) {
            throw new ArchiveIntegrityException('Portable archive transfer does not exist.');
        }

        return $transfer;
    }

    private function transferForUser(User $user, string $transferId, string $direction): object
    {
        $transfer = DB::table('portable_archive_transfers')->where('portable_archive_transfer_id', $transferId)
            ->where('user_id', $user->user_id)->where('direction', $direction)->first();
        if ($transfer === null) {
            throw new ArchiveIntegrityException('Portable archive transfer does not exist.');
        }

        return $transfer;
    }

    private function part(string $transferId, int $sequence): object
    {
        $part = DB::table('portable_archive_parts')->where('portable_archive_transfer_id', $transferId)->where('sequence', $sequence)->first();
        if ($part === null) {
            throw new ArchiveIntegrityException('Portable archive part does not exist.');
        }

        return $part;
    }

    private function transferDirectory(string $direction, string $transferId): string
    {
        if (! in_array($direction, ['export', 'import'], true) || ! Str::isUuid($transferId)) {
            throw new ArchiveIntegrityException('Portable archive path identity is invalid.');
        }

        return rtrim((string) config('archive.portable_root'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$direction.DIRECTORY_SEPARATOR.$transferId;
    }

    private function safeExistingTransferFile(string $direction, string $transferId, string $path): string
    {
        $directory = realpath($this->transferDirectory($direction, $transferId));
        $real = realpath($path);
        if (! is_string($directory) || ! is_string($real)
            || ! str_starts_with($real, $directory.DIRECTORY_SEPARATOR)
            || ! is_file($real)) {
            throw new ArchiveIntegrityException('Portable archive file escapes its staging directory.');
        }

        return $real;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new ArchiveIntegrityException('Unable to create portable archive directory.');
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($directory);
    }

    private function writeLine($gz, string $line): void
    {
        if (gzwrite($gz, $line) !== strlen($line)) {
            throw new ArchiveIntegrityException('Unable to write complete portable archive row.');
        }
    }

    private function assertLineSize(string $line): void
    {
        if (strlen($line) > (int) config('archive.portable_line_max_bytes')) {
            throw new ArchiveIntegrityException('Portable archive line exceeds the configured limit.');
        }
    }

    private function logicalKey(array $data): string
    {
        return $data['exchange'].'|'.$data['symbol'].'|'.$data['period'].'|'.$data['microtimestamp'];
    }
}
