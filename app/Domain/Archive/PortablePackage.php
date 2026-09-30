<?php

namespace App\Domain\Archive;

use App\Models\Ticker;
use Illuminate\Support\Facades\DB;

final class PortablePackage
{
    public function export(string $path, array $datasets = ['tickers']): array
    {
        $datasets = array_values(array_unique($datasets));
        $unsupported = array_diff($datasets, ['tickers']);
        if ($unsupported !== []) {
            throw new ArchiveIntegrityException('Unsupported portable dataset(s): '.implode(', ', $unsupported));
        }
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new ArchiveIntegrityException('Unable to create export directory.');
        }
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(6));
        $gz = gzopen($tmp, 'wb'.(int) config('archive.gzip_level'));
        if ($gz === false) {
            throw new ArchiveIntegrityException('Unable to create portable export.');
        }
        $counts = [];
        try {
            $header = [
                'record_type' => 'manifest', 'portable_format' => PortableJson::FORMAT,
                'format_version' => (int) config('archive.format_version'), 'datasets' => $datasets,
                'created_at' => now('UTC')->toIso8601String(), 'secrets_included' => false,
            ];
            gzwrite($gz, PortableJson::encode($header)."\n");
            if (in_array('tickers', $datasets, true)) {
                $counts['tickers'] = 0;
                foreach (Ticker::query()->orderBy('exchange')->orderBy('symbol')->orderBy('period')->orderBy('microtimestamp')->lazy(500) as $ticker) {
                    $record = ['record_type' => 'row', 'dataset' => 'tickers', 'data' => [
                        'ticker_id' => (string) $ticker->ticker_id, 'exchange' => (string) $ticker->exchange,
                        'symbol' => (string) $ticker->symbol, 'period' => (string) $ticker->period,
                        'microtimestamp' => (int) $ticker->microtimestamp,
                        'payload' => json_decode($ticker->payload, true, flags: JSON_THROW_ON_ERROR),
                    ]];
                    gzwrite($gz, PortableJson::encode($record)."\n");
                    $counts['tickers']++;
                }
            }
        } finally {
            gzclose($gz);
        }
        if (! rename($tmp, $path)) {
            @unlink($tmp);
            throw new ArchiveIntegrityException('Unable to publish portable export.');
        }

        return ['path' => $path, 'sha256' => hash_file('sha256', $path), 'compressed_size' => filesize($path), 'rows' => $counts];
    }

    public function import(string $path, bool $validateOnly = false): array
    {
        if (! is_file($path)) {
            throw new ArchiveIntegrityException('Portable import file does not exist.');
        }
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new ArchiveIntegrityException('Unable to open portable import.');
        }
        $header = null;
        $inserted = $identical = $rows = 0;
        $pending = [];
        try {
            while (! gzeof($gz)) {
                $line = gzgets($gz);
                if ($line === false) {
                    break;
                }
                if (trim($line) === '') {
                    continue;
                }
                $record = PortableJson::decode(trim($line));
                if ($header === null) {
                    if (($record['record_type'] ?? null) !== 'manifest' || ($record['portable_format'] ?? null) !== PortableJson::FORMAT
                        || ($record['format_version'] ?? null) !== (int) config('archive.format_version')
                        || ($record['secrets_included'] ?? true) !== false) {
                        throw new ArchiveIntegrityException('Unsupported or unsafe portable package manifest.');
                    }
                    $header = $record;

                    continue;
                }
                if (($record['record_type'] ?? null) !== 'row' || ($record['dataset'] ?? null) !== 'tickers' || ! is_array($record['data'] ?? null)) {
                    throw new ArchiveIntegrityException('Unsupported portable package row.');
                }
                $data = $record['data'];
                foreach (['ticker_id', 'exchange', 'symbol', 'period', 'microtimestamp', 'payload'] as $field) {
                    if (! array_key_exists($field, $data)) {
                        throw new ArchiveIntegrityException('Portable ticker row is missing '.$field.'.');
                    }
                }
                $existing = Ticker::query()->where('exchange', $data['exchange'])->where('symbol', $data['symbol'])
                    ->where('period', $data['period'])->where('microtimestamp', $data['microtimestamp'])->first();
                if ($existing !== null) {
                    $hot = json_decode($existing->payload, true, flags: JSON_THROW_ON_ERROR);
                    if (PortableJson::encode($hot) !== PortableJson::encode($data['payload'])) {
                        throw new ArchiveIntegrityException('Portable import conflict at '.$data['exchange'].'|'.$data['symbol'].'|'.$data['period'].'|'.$data['microtimestamp'].'.');
                    }
                    $identical++;
                    $rows++;

                    continue;
                }
                if (! $validateOnly) {
                    $pending[] = [
                        'ticker_id' => $data['ticker_id'], 'exchange' => $data['exchange'], 'symbol' => $data['symbol'],
                        'period' => $data['period'], 'microtimestamp' => $data['microtimestamp'],
                        'payload' => PortableJson::encode($data['payload']), 'created_at' => now(), 'updated_at' => now(),
                    ];
                    if (count($pending) >= 250) {
                        DB::table('tickers')->insert($pending);
                        $inserted += count($pending);
                        $pending = [];
                    }
                } else {
                    $inserted++;
                }
                $rows++;
            }
            if ($header === null) {
                throw new ArchiveIntegrityException('Portable package is empty.');
            }
            if (! $validateOnly && $pending !== []) {
                DB::table('tickers')->insert($pending);
                $inserted += count($pending);
            }
        } finally {
            gzclose($gz);
        }

        return ['rows' => $rows, 'inserted' => $inserted, 'identical' => $identical, 'validated_only' => $validateOnly];
    }
}
