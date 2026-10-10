<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Features\CoinGeckoClient;
use App\Domain\Features\CoinGeckoMappingManager;
use App\Http\Controllers\Controller;
use App\Models\CoinGeckoMarketMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function update(Request $request, CoinGeckoMarketMapping $mapping, CoinGeckoClient $client,
        CoinGeckoMappingManager $mappingManager): RedirectResponse
    {
        abort_unless(in_array($mapping->status, ['unmapped', 'ambiguous'], true)
            || ($mapping->status === 'resolved' && $mapping->manually_mapped), 422,
            'Only unresolved coin identities can be mapped. Unsupported quote currencies cannot be overridden.');
        $data = $request->validate([
            'coin_id' => ['required', 'string', 'max:128'],
            'apply_same_base' => ['sometimes', 'boolean'],
        ]);
        $coin = collect($client->get('/coins/list'))->firstWhere('id', $data['coin_id']);
        if (! is_array($coin) || ! isset($coin['id'], $coin['name'])) {
            return back()->withErrors(['coin_id' => 'Select a coin from the CoinGecko catalogue.']);
        }

        // A ticker can name different assets on different exchanges. Reusing
        // an identity is opt-in, limited to still-unresolved markets, and must
        // validate every exact quote. Never overwrite another resolved choice.
        $targets = null;
        if ($request->boolean('apply_same_base')) {
            $base = strtoupper(trim((string) $mapping->base_symbol));
            if ($base === '' || strcasecmp((string) ($coin['symbol'] ?? ''), $base) !== 0) {
                return back()->withErrors(['coin_id' => 'Bulk mapping requires an exact CoinGecko ticker match.']);
            }
            if (CoinGeckoMarketMapping::query()
                ->whereRaw('UPPER(base_symbol) = ?', [$base])
                ->where('status', 'resolved')
                ->whereNotNull('coin_id')
                ->where('coin_id', '!=', $coin['id'])
                ->where($mapping->getKeyName(), '!=', $mapping->getKey())
                ->exists()) {
                return back()->withErrors(['coin_id' => 'Other markets with this ticker already use a different CoinGecko asset. Map them individually.']);
            }
            try {
                $supported = array_map('strtolower', $client->get('/simple/supported_vs_currencies'));
            } catch (\Throwable $exception) {
                report($exception);

                return back()->withErrors(['coin_id' => 'Unable to verify CoinGecko quote currencies. Try again.']);
            }
            $targets = CoinGeckoMarketMapping::query()
                ->whereRaw('UPPER(base_symbol) = ?', [$base])
                ->where($mapping->getKeyName(), '!=', $mapping->getKey())
                ->whereIn('status', ['pending', 'ambiguous', 'unmapped'])
                ->whereIn('vs_currency', $supported);
        }

        // Re-saving an existing ID must not discard known category metadata.
        $values = [
            'coin_id' => $coin['id'],
            'coin_name' => $coin['name'],
            'category' => $mapping->coin_id === $coin['id'] && $mapping->category
                ? $mapping->category : $mappingManager->resolvePrimaryCategory((string) $coin['id']),
            'status' => 'resolved',
            'last_error' => null,
            'resolved_at' => now(),
            'manually_mapped' => true,
        ];
        $updated = DB::transaction(function () use ($mapping, $values, $targets): int {
            $mapping->update($values);

            return $targets?->update($values) ?? 0;
        });

        return back()->with('status', $updated > 0
            ? 'CoinGecko coin mapping updated for '.($updated + 1).' markets.'
            : 'CoinGecko coin mapping updated.');
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
