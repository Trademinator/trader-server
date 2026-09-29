<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Operations\ActionLog;
use App\Http\Controllers\Controller;
use App\Models\MarketFeed;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'unverified'])]]);
        $users = User::query()->select(['user_id', 'name', 'email', 'email_verified_at', 'suspended_at', 'created_at', 'last_login_at', 'last_seen_at'])
            ->withCount(['subscriptions', 'subscriptions as active_subscriptions_count' => fn ($query) => $query->where('active', true)])
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where(fn ($q) => $q
                ->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%')->orWhere('user_id', $term)))
            ->when(($filters['status'] ?? '') === 'active', fn ($q) => $q->whereNull('suspended_at'))
            ->when(($filters['status'] ?? '') === 'suspended', fn ($q) => $q->whereNotNull('suspended_at'))
            ->when(($filters['status'] ?? '') === 'unverified', fn ($q) => $q->whereNull('email_verified_at'))
            ->orderByDesc('created_at')->orderBy('user_id')->paginate(25)->withQueryString();

        return view('owner.users', compact('users'));
    }

    public function show(User $user): View
    {
        $subscriptions = $user->subscriptions()->with('market.exchange', 'market.feed')
            ->orderByDesc('created_at')->orderBy('market_subscription_id')->paginate(25);

        return view('owner.user', compact('user', 'subscriptions'));
    }

    public function update(Request $request, User $user, ActionLog $log): RedirectResponse
    {
        $data = $request->validate(['operation' => ['required', Rule::in(['profile', 'suspend', 'restore', 'revoke-api'])],
            'name' => ['required_if:operation,profile', 'string', 'max:255'],
            'email' => ['required_if:operation,profile', 'email', 'max:255', Rule::unique('users')->ignore($user->user_id, 'user_id')]]);
        abort_if($user->isOwner() && in_array($data['operation'], ['suspend', 'profile'], true), 422,
            'The configured owner cannot be suspended or edited here. Use your own profile settings.');
        $count = DB::transaction(function () use ($user, $data): int {
            $target = User::query()->lockForUpdate()->findOrFail($user->user_id);
            $count = 0;
            switch ($data['operation']) {
                case 'profile':
                    if ($target->email !== $data['email']) {
                        $target->email_verified_at = null;
                    }
                    $target->name = $data['name'];
                    $target->email = $data['email'];
                    break;
                case 'suspend':
                    $target->suspended_at ??= now();
                    $target->api_key = null;
                    $target->remember_token = Str::random(60);
                    $count = $target->subscriptions()->where('active', true)->update(['active' => false]);
                    MarketFeed::query()->whereIn('market_id', $target->subscriptions()->select('market_id'))
                        ->whereDoesntHave('market.subscriptions', fn ($query) => $query->where('active', true))
                        ->whereNull('lease_token')->update(['status' => 'idle', 'next_pull_at' => null]);
                    break;
                case 'restore':
                    $target->suspended_at = null;
                    break;
                case 'revoke-api':
                    $target->api_key = null;
                    break;
            }
            $target->save();

            return $count;
        });
        $log->write('owner.user.'.str_replace('-', '_', $data['operation']),
            ['subject_id' => $user->user_id, 'outcome' => 'completed', 'rows' => $count]);

        return back()->with('status', match ($data['operation']) {
            'suspend' => 'Account suspended, API key revoked, and active subscriptions stopped. Existing sessions will be rejected on their next request.',
            'restore' => 'Account restored. The user can choose which markets to subscribe to again.',
            'revoke-api' => 'API key revoked.',
            default => 'User profile updated. A changed email address must be verified again.',
        });
    }
}
