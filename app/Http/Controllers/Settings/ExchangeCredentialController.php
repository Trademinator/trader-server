<?php

namespace App\Http\Controllers\Settings;

use App\Domain\MarketData\ExchangeCredentials;
use App\Http\Controllers\Controller;
use App\Models\Exchange;
use App\Models\MarketFeed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ExchangeCredentialController extends Controller
{
    public function index(Request $request, ExchangeCredentials $credentials): Response
    {
        $exchanges = Exchange::query()->whereIn('class', \ccxt\Exchange::$exchanges)->orderBy('name')->get(['exchange_id', 'class', 'name']);
        $selected = $exchanges->firstWhere('exchange_id', $request->query('exchange'));
        $required = $selected === null ? [] : $credentials->requiredFields($selected);
        $supported = $selected !== null && $required !== [] && array_diff($required, array_keys(ExchangeCredentials::FIELDS)) === [];

        return response()->view('settings.exchange-keys', [
            'exchanges' => $exchanges,
            'selected' => $selected,
            'required' => $required,
            'fields' => ExchangeCredentials::FIELDS,
            'supported' => $supported,
            'saved' => $request->user()->exchangeCredentials()->with('exchange:exchange_id,name,class')
                ->get(['exchange_credential_id', 'exchange_id', 'user_id', 'is_shared']),
            'sharedCounts' => $credentials->shared()->selectRaw('exchange_id, COUNT(*) AS total')
                ->groupBy('exchange_id')->pluck('total', 'exchange_id'),
            'isOwner' => $request->user()->isOwner(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, Exchange $exchange, ExchangeCredentials $credentials): RedirectResponse
    {
        $user = $request->user();
        abort_if($request->boolean('is_shared') && ! $user->isOwner(), 403);
        $required = $credentials->requiredFields($exchange);
        abort_if($required === [] || array_diff($required, array_keys(ExchangeCredentials::FIELDS)) !== [], 422,
            'This adapter does not support read-only API credentials in this form. Never provide a wallet private key.');
        $existing = $user->exchangeCredentials()->where('exchange_id', $exchange->exchange_id)->first();
        $submitted = $request->input('credentials', []);
        $replacing = $existing === null || (is_array($submitted) && count(array_filter($submitted,
            fn (mixed $value): bool => $value !== null && $value !== '')) > 0);
        $rules = [
            'credentials' => ['sometimes', 'array:'.implode(',', array_keys(ExchangeCredentials::FIELDS))],
            'read_only_confirmed' => ['accepted'],
            'is_shared' => ['sometimes', 'boolean'],
        ];
        foreach (ExchangeCredentials::FIELDS as $field => $label) {
            $rules['credentials.'.$field] = [Rule::requiredIf($replacing && in_array($field, $required, true)), 'nullable', 'string', $field === 'secret' ? 'max:16384' : 'max:2048'];
        }
        $data = Validator::make($request->all(), $rules, [
            'read_only_confirmed.accepted' => 'Confirm that these credentials are read-only and restricted to public market information wherever supported.',
        ])->validate();
        $changes = [];
        if ($replacing) {
            $values = array_filter($data['credentials'] ?? [], fn (?string $value): bool => $value !== null && $value !== '');
            if ($exchange->class === 'coinbase' && isset($values['secret'])) {
                $values['secret'] = str_replace('\\n', "\n", $values['secret']);
            }
            $changes['credentials'] = $values;
        }
        $shared = $user->isOwner() && $request->boolean('is_shared');
        $wasShared = $existing?->is_shared ?? false;
        $user->exchangeCredentials()->select(['exchange_credential_id', 'user_id', 'exchange_id', 'is_shared'])
            ->updateOrCreate(['exchange_id' => $exchange->exchange_id], [
                ...$changes, 'is_shared' => $shared,
            ]);
        $this->resumeFeeds($request, $exchange, $shared || $wasShared);

        return to_route('settings.exchange-keys.index', ['exchange' => $exchange->exchange_id])
            ->with('status', 'exchange-credentials-saved');
    }

    public function destroy(Request $request, Exchange $exchange): RedirectResponse
    {
        $credential = $request->user()->exchangeCredentials()->where('exchange_id', $exchange->exchange_id)->firstOrFail();
        $shared = $credential->is_shared;
        $credential->delete();
        $this->resumeFeeds($request, $exchange, $shared);

        return to_route('settings.exchange-keys.index', ['exchange' => $exchange->exchange_id])
            ->with('status', 'exchange-credentials-deleted');
    }

    private function resumeFeeds(Request $request, Exchange $exchange, bool $shared): void
    {
        MarketFeed::query()->whereNull('lease_token')->whereIn('status', ['blocked', 'error', 'pending'])
            ->whereHas('market', fn (Builder $query) => $query->where('exchange_id', $exchange->exchange_id)
                ->whereHas('subscriptions', fn (Builder $query) => $query->where('active', true)
                    ->when(! $shared, fn (Builder $query) => $query->where('user_id', $request->user()->user_id))))
            ->update(['status' => 'pending', 'next_pull_at' => now(), 'last_error' => null]);
    }
}
