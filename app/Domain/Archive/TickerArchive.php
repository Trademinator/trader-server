<?php

namespace App\Domain\Archive;

use App\Models\Ticker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class TickerArchive
{
    public function __construct(private ArchiveCatalog $catalog) {}

    public function archiveMonth(string $exchange, string $symbol, string $period, int $year, int $month, bool $dryRun = false): array
    {
        if (! config('archive.enabled')) {
            throw new ArchiveIntegrityException('Archiving is disabled.');
        }
        if ($month < 1 || $month > 12 || $year < 1970 || $year > 9999) {
            throw new ArchiveIntegrityException('Invalid archive month.');
        }
        $start = gmmktime(0, 0, 0, $month, 1, $year) * 1000;
        $nextMonth = $month === 12 ? gmmktime(0, 0, 0, 1, 1, $year + 1) : gmmktime(0, 0, 0, $month + 1, 1, $year);
        $endExclusive = $nextMonth * 1000;
        $query = Ticker::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
            ->where('microtimestamp', '>=', $start)->where('microtimestamp', '<', $endExclusive)->orderBy('microtimestamp');
        $count = (clone $query)->count();
        if ($count === 0) {
            return ['status' => 'empty', 'row_count' => 0, 'start_ms' => $start, 'end_ms' => $endExclusive - 1];
        }
        $relativeBase = sprintf('tickers/%s/%s/%s/%04d-%02d', rawurlencode($exchange), rawurlencode($symbol), rawurlencode($period), $year, $month);
        $dataRelative = $relativeBase.'.jsonl.gz';
        $manifestRelative = $relativeBase.'.manifest.json';
        if ($dryRun) {
            return ['status' => 'dry-run', 'row_count' => $count, 'data' => $dataRelative, 'manifest' => $manifestRelative];
        }

        $root = rtrim((string) config('archive.root'), DIRECTORY_SEPARATOR);
        $dataPath = $root.DIRECTORY_SEPARATOR.$dataRelative;
        $manifestPath = $root.DIRECTORY_SEPARATOR.$manifestRelative;
        $directory = dirname($dataPath);
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new ArchiveIntegrityException('Unable to create archive directory.');
        }
        $tmpData = $dataPath.'.tmp.'.bin2hex(random_bytes(6));
        $tmpManifest = $manifestPath.'.tmp.'.bin2hex(random_bytes(6));
        $gz = gzopen($tmpData, 'wb'.(int) config('archive.gzip_level'));
        if ($gz === false) {
            throw new ArchiveIntegrityException('Unable to open temporary gzip archive.');
        }
        $rows = 0;
        $first = $last = null;
        try {
            foreach ($query->lazyById(500, 'microtimestamp') as $ticker) {
                $record = $this->record($ticker);
                $line = PortableJson::encode($record)."\n";
                if (gzwrite($gz, $line) !== strlen($line)) {
                    throw new ArchiveIntegrityException('Unable to write complete archive row.');
                }
                $key = $this->logicalKey($record);
                $first ??= $key;
                $last = $key;
                $rows++;
            }
        } finally {
            gzclose($gz);
        }
        if ($rows !== $count) {
            @unlink($tmpData);
            throw new ArchiveIntegrityException('Archive row count changed while streaming source rows.');
        }
        $sha = hash_file('sha256', $tmpData);
        $size = filesize($tmpData);
        if ($sha === false || $size === false) {
            @unlink($tmpData);
            throw new ArchiveIntegrityException('Unable to hash completed archive.');
        }
        $manifest = [
            'portable_format' => PortableJson::FORMAT,
            'format_version' => (int) config('archive.format_version'),
            'schema_version' => (int) config('archive.ticker_schema_version'),
            'logical_type' => 'tickers',
            'compression' => 'gzip',
            'market' => ['exchange' => $exchange, 'symbol' => $symbol, 'period' => $period],
            'coverage' => ['start_ms' => $start, 'end_ms' => $endExclusive - 1],
            'row_count' => $rows,
            'first_key' => $first,
            'last_key' => $last,
            'data_file' => basename($dataPath),
            'sha256' => $sha,
            'compressed_size' => $size,
            'created_at' => now('UTC')->toIso8601String(),
            'verification' => ['state' => 'pending', 'error' => null],
        ];
        file_put_contents($tmpManifest, PortableJson::encode($manifest)."\n", LOCK_EX);
        if (! rename($tmpData, $dataPath) || ! rename($tmpManifest, $manifestPath)) {
            @unlink($tmpData);
            @unlink($tmpManifest);
            throw new ArchiveIntegrityException('Unable to atomically publish archive files.');
        }
        $verified = $this->verifyManifest($manifestRelative);
        $this->catalog->upsert($verified, $manifestRelative);

        return ['status' => 'verified', 'row_count' => $rows, 'data' => $dataRelative, 'manifest' => $manifestRelative, 'sha256' => $sha];
    }

    public function verifyManifest(string $manifestRelative): array
    {
        $root = rtrim((string) config('archive.root'), DIRECTORY_SEPARATOR);
        $manifestPath = $this->safePath($root, $manifestRelative);
        if (! is_file($manifestPath)) {
            throw new ArchiveIntegrityException('Archive manifest is missing: '.$manifestRelative);
        }
        $manifest = PortableJson::decode(trim((string) file_get_contents($manifestPath)));
        $this->validateManifest($manifest);
        $dataPath = dirname($manifestPath).DIRECTORY_SEPARATOR.$manifest['data_file'];
        if (! is_file($dataPath)) {
            throw new ArchiveIntegrityException('Archive data file is missing: '.$manifest['data_file']);
        }
        $sha = hash_file('sha256', $dataPath);
        $size = filesize($dataPath);
        if (! is_string($sha) || ! hash_equals($manifest['sha256'], $sha) || $size !== $manifest['compressed_size']) {
            throw new ArchiveIntegrityException('Archive checksum or compressed size does not match its manifest.');
        }
        $rows = 0;
        $first = $last = null;
        foreach ($this->streamData($dataPath) as $record) {
            $this->validateTickerRecord($record, $manifest);
            $key = $this->logicalKey($record);
            $first ??= $key;
            $last = $key;
            $rows++;
        }
        if ($rows !== $manifest['row_count'] || $first !== $manifest['first_key'] || $last !== $manifest['last_key']) {
            throw new ArchiveIntegrityException('Archive row count or boundary keys do not match its manifest.');
        }
        $manifest['verification'] = ['state' => 'verified', 'error' => null];
        $manifest['verified_at'] = now('UTC')->toIso8601String();
        file_put_contents($manifestPath, PortableJson::encode($manifest)."\n", LOCK_EX);
        $this->catalog->upsert($manifest, $manifestRelative);

        return $manifest;
    }

    public function verifyAll(): array
    {
        $results = ['verified' => 0, 'failed' => 0, 'errors' => []];
        foreach ($this->manifestPaths() as $path) {
            try {
                $this->verifyManifest($path);
                $results['verified']++;
            } catch (\Throwable $error) {
                $results['failed']++;
                $results['errors'][$path] = $error->getMessage();
                DB::table('archive_catalog')->where('path', $path)->update([
                    'verification_state' => 'failed', 'verification_error' => $error->getMessage(), 'verified_at' => null, 'updated_at' => now(),
                ]);
            }
        }

        $results['catalog_health'] = $this->catalog->health();

        return $results;
    }

    public function rebuildCatalog(): array
    {
        $seen = [];
        $verified = $failed = 0;
        foreach ($this->manifestPaths() as $path) {
            $seen[] = $path;
            try {
                $this->verifyManifest($path);
                $verified++;
            } catch (\Throwable $error) {
                $failed++;
            }
        }
        DB::table('archive_catalog')->whereNotIn('path', $seen === [] ? ['__none__'] : $seen)->delete();

        return ['verified' => $verified, 'failed' => $failed, 'catalog_rows' => count($seen)];
    }

    public function stream(string $exchange, string $symbol, string $period, int $fromMs, int $toMs): \Generator
    {
        $previousEnd = null;
        foreach ($this->catalog->verifiedTickers($exchange, $symbol, $period, $fromMs, $toMs) as $catalog) {
            if ($previousEnd !== null && (int) $catalog->range_start_ms <= $previousEnd) {
                throw new ArchiveIntegrityException('Overlapping archive catalog ranges require OWNER repair before cold-history reads.');
            }
            $previousEnd = (int) $catalog->range_end_ms;
            $manifest = $this->verifyManifest($catalog->path);
            $dataPath = dirname($this->safePath(rtrim((string) config('archive.root'), DIRECTORY_SEPARATOR), $catalog->path))
                .DIRECTORY_SEPARATOR.$manifest['data_file'];
            foreach ($this->streamData($dataPath) as $record) {
                $ts = (int) $record['microtimestamp'];
                if ($ts < $fromMs || $ts > $toMs) {
                    continue;
                }
                yield $ts => $record;
            }
        }
    }

    public function restoreManifest(string $manifestRelative, bool $validateOnly = false, ?int $fromMs = null, ?int $toMs = null): array
    {
        $manifest = $this->verifyManifest($manifestRelative);
        $root = rtrim((string) config('archive.root'), DIRECTORY_SEPARATOR);
        $manifestPath = $this->safePath($root, $manifestRelative);
        $dataPath = dirname($manifestPath).DIRECTORY_SEPARATOR.$manifest['data_file'];
        $inserted = $identical = 0;
        $fromMs ??= (int) $manifest['coverage']['start_ms'];
        $toMs ??= (int) $manifest['coverage']['end_ms'];
        if ($fromMs < (int) $manifest['coverage']['start_ms'] || $toMs > (int) $manifest['coverage']['end_ms'] || $fromMs > $toMs) {
            throw new ArchiveIntegrityException('Restore range must be inside the manifest coverage.');
        }
        foreach ($this->streamData($dataPath) as $record) {
            if ((int) $record['microtimestamp'] < $fromMs || (int) $record['microtimestamp'] > $toMs) {
                continue;
            }
            $existing = Ticker::query()->where('exchange', $record['exchange'])->where('symbol', $record['symbol'])
                ->where('period', $record['period'])->where('microtimestamp', $record['microtimestamp'])->first();
            if ($existing !== null) {
                $hot = json_decode($existing->payload, true, flags: JSON_THROW_ON_ERROR);
                if (PortableJson::encode($hot) !== PortableJson::encode($record['payload'])) {
                    throw new ArchiveIntegrityException('Restore conflict at '.$this->logicalKey($record).'.');
                }
                $identical++;

                continue;
            }
            if (! $validateOnly) {
                DB::table('tickers')->insert([
                    'ticker_id' => $record['ticker_id'], 'exchange' => $record['exchange'], 'symbol' => $record['symbol'],
                    'period' => $record['period'], 'microtimestamp' => $record['microtimestamp'], 'payload' => PortableJson::encode($record['payload']),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $inserted++;
        }

        return ['inserted' => $inserted, 'identical' => $identical, 'validated_only' => $validateOnly];
    }

    private function record(Ticker $ticker): array
    {
        return [
            'ticker_id' => (string) $ticker->ticker_id,
            'exchange' => (string) $ticker->exchange,
            'symbol' => (string) $ticker->symbol,
            'period' => (string) $ticker->period,
            'microtimestamp' => (int) $ticker->microtimestamp,
            'payload' => json_decode($ticker->payload, true, flags: JSON_THROW_ON_ERROR),
            'created_at' => $ticker->created_at?->copy()->utc()->toIso8601String(),
            'updated_at' => $ticker->updated_at?->copy()->utc()->toIso8601String(),
        ];
    }

    private function validateManifest(array $manifest): void
    {
        if (($manifest['portable_format'] ?? null) !== PortableJson::FORMAT
            || ($manifest['format_version'] ?? null) !== (int) config('archive.format_version')
            || ($manifest['schema_version'] ?? null) !== (int) config('archive.ticker_schema_version')
            || ($manifest['logical_type'] ?? null) !== 'tickers' || ($manifest['compression'] ?? null) !== 'gzip') {
            throw new ArchiveIntegrityException('Unsupported archive manifest format or schema version.');
        }
        foreach (['exchange', 'symbol', 'period'] as $field) {
            if (! is_string($manifest['market'][$field] ?? null) || $manifest['market'][$field] === '') {
                throw new ArchiveIntegrityException('Archive manifest market identity is incomplete.');
            }
        }
        foreach (['start_ms', 'end_ms'] as $field) {
            if (! is_int($manifest['coverage'][$field] ?? null) || $manifest['coverage'][$field] < 0) {
                throw new ArchiveIntegrityException('Archive coverage is invalid.');
            }
        }
        if (! is_int($manifest['row_count'] ?? null) || $manifest['row_count'] < 1
            || ! is_string($manifest['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $manifest['sha256'])
            || ! is_int($manifest['compressed_size'] ?? null) || $manifest['compressed_size'] < 1) {
            throw new ArchiveIntegrityException('Archive manifest integrity metadata is invalid.');
        }
    }

    private function validateTickerRecord(array $record, array $manifest): void
    {
        if (! Str::isUuid((string) ($record['ticker_id'] ?? ''))
            || ($record['exchange'] ?? null) !== $manifest['market']['exchange']
            || ($record['symbol'] ?? null) !== $manifest['market']['symbol']
            || ($record['period'] ?? null) !== $manifest['market']['period']
            || ! is_int($record['microtimestamp'] ?? null)
            || $record['microtimestamp'] < $manifest['coverage']['start_ms']
            || $record['microtimestamp'] > $manifest['coverage']['end_ms']
            || ! is_array($record['payload'] ?? null)) {
            throw new ArchiveIntegrityException('Malformed ticker row in archive.');
        }
        foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
            if (! is_string($record['payload'][$field] ?? null) && ! is_int($record['payload'][$field] ?? null)) {
                throw new ArchiveIntegrityException('Archived financial values must remain exact decimal strings or integers.');
            }
        }
    }

    private function logicalKey(array $record): string
    {
        return $record['exchange'].'|'.$record['symbol'].'|'.$record['period'].'|'.$record['microtimestamp'];
    }

    private function streamData(string $path): \Generator
    {
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new ArchiveIntegrityException('Unable to open archive data file.');
        }
        $lineNo = 0;
        try {
            while (! gzeof($gz)) {
                $line = gzgets($gz);
                if ($line === false) {
                    if (! gzeof($gz)) {
                        throw new ArchiveIntegrityException('Unable to read archive data stream.');
                    }
                    break;
                }
                $lineNo++;
                if (trim($line) === '') {
                    continue;
                }
                try {
                    yield PortableJson::decode(trim($line));
                } catch (JsonException|ArchiveIntegrityException $error) {
                    throw new ArchiveIntegrityException('Malformed archive row '.$lineNo.': '.$error->getMessage(), previous: $error);
                }
            }
        } finally {
            gzclose($gz);
        }
    }

    private function manifestPaths(): array
    {
        $root = rtrim((string) config('archive.root'), DIRECTORY_SEPARATOR);
        if (! is_dir($root)) {
            return [];
        }
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.manifest.json')) {
                $paths[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function safePath(string $root, string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '../')) {
            throw new ArchiveIntegrityException('Unsafe archive path.');
        }

        return $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
