<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Features\CoinGeckoClient;
use App\Http\Controllers\Controller;
use App\Models\CoinGeckoMarketMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class CoinGeckoMappingController extends Controller
{
    public function index(): View
    {
        return view('owner.coingecko-mappings', [
            'mappings' => CoinGeckoMarketMapping::query()
                ->with('market.exchange')
                ->where(function ($query): void {
                    $query->whereIn('status', ['unmapped', 'ambiguous', 'unsupported'])
                        ->orWhere('manually_mapped', true);
                })
                ->whereHas('market')
                ->orderBy('created_at', 'desc')
                ->paginate(50),
        ]);
    }

    public function coins(Request $request, CoinGeckoClient $client): JsonResponse
    {
        $query = Str::lower(trim((string) $request->query('q', '')));
        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            return response()->json(['results' => []]);
        }

        // Cache the catalogue; do not issue one CoinGecko request per keypress.
        $coins = Cache::remember('trademinator:coingecko:coins-list', now()->addDay(),
            fn (): array => $client->get('/coins/list'));

        $exact = $request->boolean('exact');
        $results = [];
        foreach ($coins as $coin) {
            if (! is_array($coin) || ! isset($coin['id'], $coin['symbol'], $coin['name'])) {
                continue;
            }
            if ($exact ? Str::lower((string) $coin['symbol']) !== $query
                : ! str_contains(Str::lower($coin['id'].' '.$coin['symbol'].' '.$coin['name']), $query)) {
                continue;
            }
            $results[] = ['id' => $coin['id'], 'text' => $coin['name'].' ('.strtoupper($coin['symbol']).') · '.$coin['id']];
            if (count($results) >= 50) {
                break;
            }
        }

        return response()->json(['results' => $results]);
    }

    public function update(Request $request, CoinGeckoMarketMapping $mapping, CoinGeckoClient $client): RedirectResponse
    {
        abort_unless(in_array($mapping->status, ['unmapped', 'ambiguous'], true)
            || ($mapping->status === 'resolved' && $mapping->manually_mapped), 422,
            'Only unresolved coin identities can be mapped. Unsupported quote currencies cannot be overridden.');
        $data = $request->validate(['coin_id' => ['required', 'string', 'max:128']]);
        $coin = collect($client->get('/coins/list'))->firstWhere('id', $data['coin_id']);
        if (! is_array($coin) || ! isset($coin['id'], $coin['name'])) {
            return back()->withErrors(['coin_id' => 'Select a coin from the CoinGecko catalogue.']);
        }
        $mapping->update([
            'coin_id' => $coin['id'],
            'coin_name' => $coin['name'],
            'category' => null,
            'status' => 'resolved',
            'last_error' => null,
            'resolved_at' => now(),
            'manually_mapped' => true,
        ]);

        return back()->with('status', 'CoinGecko coin mapping updated.');
    }

    public function destroy(CoinGeckoMarketMapping $mapping): RedirectResponse
    {
        abort_unless(in_array($mapping->status, ['unmapped', 'ambiguous', 'unsupported'], true)
            || ($mapping->status === 'resolved' && $mapping->manually_mapped), 422);
        // Delete the pending exception, not the actual market or its exchange feed.
        // The next subscription reconciliation may recreate an automatic mapping.
        $mapping->delete();

        return back()->with('status', 'Mapping record deleted.');
    }
}
