<?php

namespace App\Domain\Archive;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ArchiveCatalog
{
    public function upsert(array $manifest, string $manifestPath): string
    {
        $existing = DB::table('archive_catalog')->where('path', $manifestPath)->first();
        $id = $existing?->archive_id ?? (string) Str::uuid7();
        DB::table('archive_catalog')->updateOrInsert(['path' => $manifestPath], [
            'archive_id' => $id,
            'logical_type' => $manifest['logical_type'],
            'exchange' => $manifest['market']['exchange'] ?? null,
            'symbol' => $manifest['market']['symbol'] ?? null,
            'period' => $manifest['market']['period'] ?? null,
            'range_start_ms' => $manifest['coverage']['start_ms'],
            'range_end_ms' => $manifest['coverage']['end_ms'],
            'row_count' => $manifest['row_count'],
            'format_version' => $manifest['format_version'],
            'schema_version' => $manifest['schema_version'],
            'compression' => $manifest['compression'],
            'sha256' => $manifest['sha256'],
            'compressed_size' => $manifest['compressed_size'],
            'verification_state' => $manifest['verification']['state'] ?? 'pending',
            'verification_error' => $manifest['verification']['error'] ?? null,
            'verified_at' => ($manifest['verification']['state'] ?? null) === 'verified' ? now() : null,
            'created_at' => $existing?->created_at ?? now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function verifiedTickers(string $exchange, string $symbol, string $period, int $fromMs, int $toMs): iterable
    {
        return DB::table('archive_catalog')->where('logical_type', 'tickers')->where('verification_state', 'verified')
            ->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
            ->where('range_end_ms', '>=', $fromMs)->where('range_start_ms', '<=', $toMs)
            ->orderBy('range_start_ms')->orderBy('path')->cursor();
    }

    public function health(): array
    {
        $rows = DB::table('archive_catalog')->orderBy('logical_type')->orderBy('exchange')->orderBy('symbol')->orderBy('period')->orderBy('range_start_ms')->get();
        $failed = $rows->where('verification_state', '!=', 'verified')->count();
        $overlaps = $gaps = [];
        $previous = [];
        foreach ($rows as $row) {
            $key = implode('|', [$row->logical_type, $row->exchange ?? '', $row->symbol ?? '', $row->period ?? '']);
            if (isset($previous[$key])) {
                $prior = $previous[$key];
                if ((int) $row->range_start_ms <= (int) $prior->range_end_ms) {
                    $overlaps[] = [$prior->path, $row->path];
                } elseif ((int) $row->range_start_ms > (int) $prior->range_end_ms + 1) {
                    $gaps[] = ['after' => $prior->path, 'before' => $row->path,
                        'start_ms' => (int) $prior->range_end_ms + 1, 'end_ms' => (int) $row->range_start_ms - 1];
                }
            }
            $previous[$key] = $row;
        }

        return ['catalog_rows' => $rows->count(), 'failed' => $failed, 'overlaps' => $overlaps, 'gaps' => $gaps];
    }

    public function rows()
    {
        return DB::table('archive_catalog')->orderByDesc('range_start_ms')->orderBy('logical_type')->paginate(50);
    }
}
