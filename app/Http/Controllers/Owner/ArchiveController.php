<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Archive\ArchiveCatalog;
use App\Domain\Archive\MultipartPortableArchive;
use App\Domain\Archive\TickerArchive;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ArchiveController extends Controller
{
    public function index(Request $request, ArchiveCatalog $catalog, MultipartPortableArchive $portable): View
    {
        return view('owner.archives', [
            'archives' => $catalog->rows(),
            'root' => config('archive.root'),
            'health' => $catalog->health(),
            'portableTransfers' => $portable->recentTransfers($request->user()),
        ]);
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

    public function export(Request $request, MultipartPortableArchive $portable): RedirectResponse
    {
        $transfer = $portable->startExport($request->user());

        return back()->with('status', 'Multipart portable export queued: '.$transfer.'.');
    }

    public function import(Request $request, MultipartPortableArchive $portable): RedirectResponse
    {
        $validated = $request->validate([
            'manifest' => ['required', 'file', 'max:'.(int) config('archive.portable_manifest_max_kb')],
            'validate_only' => ['nullable', 'boolean'],
        ]);
        $transfer = $portable->startImport($request->user(), $validated['manifest'], (bool) ($validated['validate_only'] ?? true));

        return back()->with('status', 'Multipart import created: '.$transfer.'. Upload every listed part; import starts only after all parts verify.');
    }

    public function uploadImportPart(Request $request, string $transfer, MultipartPortableArchive $portable): RedirectResponse
    {
        $validated = $request->validate([
            'part' => ['required', 'file', 'max:'.max(1, intdiv((int) config('archive.portable_part_max_compressed_bytes'), 1024))],
        ]);
        $portable->receiveImportPart($request->user(), $transfer, $validated['part']);

        return back()->with('status', 'Portable part accepted for background verification.');
    }

    public function beginImport(Request $request, string $transfer, MultipartPortableArchive $portable): RedirectResponse
    {
        $portable->beginValidatedImport($request->user(), $transfer);

        return back()->with('status', 'Verified multipart import queued.');
    }

    public function downloadManifest(Request $request, string $transfer, MultipartPortableArchive $portable): BinaryFileResponse
    {
        $path = $portable->manifestDownloadPath($request->user(), $transfer);

        return response()->download($path, 'manifest.json', ['Cache-Control' => 'no-store, private']);
    }

    public function downloadPart(Request $request, string $transfer, int $sequence, MultipartPortableArchive $portable): BinaryFileResponse
    {
        $path = $portable->partDownloadPath($request->user(), $transfer, $sequence);

        return response()->download($path, basename($path), ['Cache-Control' => 'no-store, private']);
    }
}
