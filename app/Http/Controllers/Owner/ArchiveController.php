<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Archive\ArchiveCatalog;
use App\Domain\Archive\PortablePackage;
use App\Domain\Archive\TickerArchive;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ArchiveController extends Controller
{
    public function index(ArchiveCatalog $catalog): View
    {
        return view('owner.archives', ['archives' => $catalog->rows(), 'root' => config('archive.root'), 'health' => $catalog->health()]);
    }

    public function verify(Request $request, TickerArchive $archive): RedirectResponse
    {
        $validated = $request->validate(['manifest' => ['nullable', 'string', 'max:512']]);
        $result = empty($validated['manifest']) ? $archive->verifyAll() : $archive->verifyManifest($validated['manifest']);

        return back()->with('status', 'Archive verification: '.json_encode($result, JSON_UNESCAPED_SLASHES));
    }

    public function rebuild(TickerArchive $archive): RedirectResponse
    {
        $result = $archive->rebuildCatalog();

        return back()->with('status', 'Archive catalog rebuilt: '.json_encode($result, JSON_UNESCAPED_SLASHES));
    }

    public function archive(Request $request, TickerArchive $archive): RedirectResponse
    {
        $validated = $request->validate([
            'exchange' => ['required', 'string', 'max:64'], 'symbol' => ['required', 'string', 'max:64'],
            'period' => ['required', 'string', 'max:8'], 'month' => ['required', 'date_format:Y-m'],
        ]);
        [$year, $month] = array_map('intval', explode('-', $validated['month']));
        $result = $archive->archiveMonth($validated['exchange'], $validated['symbol'], $validated['period'], $year, $month);

        return back()->with('status', 'Archive created: '.json_encode($result, JSON_UNESCAPED_SLASHES));
    }

    public function restore(Request $request, TickerArchive $archive): RedirectResponse
    {
        $validated = $request->validate(['manifest' => ['required', 'string', 'max:512'], 'validate_only' => ['nullable', 'boolean'],
            'from_ms' => ['nullable', 'integer', 'min:0'], 'to_ms' => ['nullable', 'integer', 'min:0']]);
        $result = $archive->restoreManifest($validated['manifest'], (bool) ($validated['validate_only'] ?? false),
            isset($validated['from_ms']) ? (int) $validated['from_ms'] : null, isset($validated['to_ms']) ? (int) $validated['to_ms'] : null);

        return back()->with('status', 'Archive restore: '.json_encode($result, JSON_UNESCAPED_SLASHES));
    }

    public function export(PortablePackage $portable): BinaryFileResponse
    {
        $path = storage_path('app/private/exports/trademinator-portable-'.now('UTC')->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.jsonl.gz');
        $portable->export($path, ['tickers']);

        return response()->download($path, basename($path), ['Cache-Control' => 'no-store, private'])->deleteFileAfterSend(true);
    }

    public function import(Request $request, PortablePackage $portable): RedirectResponse
    {
        $validated = $request->validate([
            'package' => ['required', 'file', 'max:1048576'], 'validate_only' => ['nullable', 'boolean'],
        ]);
        $path = $validated['package']->getRealPath();
        $result = $portable->import($path, (bool) ($validated['validate_only'] ?? true));

        return back()->with('status', 'Portable import: '.json_encode($result, JSON_UNESCAPED_SLASHES));
    }
}
