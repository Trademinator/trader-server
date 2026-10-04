<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Operations\ActionLog;
use App\Http\Controllers\Controller;
use App\Models\ClientApiKey;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApiKeyController extends Controller
{
    private const API_KEY_PREFIX_LENGTH = 20;

    public function edit(Request $request)
    {
        return view('settings.api-key', [
            'keys' => ClientApiKey::query()->where('user_id', $request->user()->user_id)
                ->orderByDesc('created_at')->get(),
            'newSecret' => session('new_client_api_key'),
        ]);
    }

    public function store(Request $request, ActionLog $log): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'expires_at' => ['nullable', 'string', 'date'],
            'expires_timezone' => ['sometimes', 'required', 'string', 'timezone:all_with_bc'],
        ]);
        $expiresAt = $this->expiry($data['expires_at'] ?? null, $data['expires_timezone'] ?? null);
        $active = ClientApiKey::query()->where('user_id', $request->user()->user_id)
            ->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();
        abort_if($active >= config('client.max_keys'), 422, 'Revoke or let an existing Client API key expire before creating another.');

        do {
            $secret = 'tmk_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $prefix = substr($secret, 0, self::API_KEY_PREFIX_LENGTH);
        } while (ClientApiKey::query()->where('prefix', $prefix)->exists());

        $key = ClientApiKey::query()->create([
            'user_id' => $request->user()->user_id,
            'label' => $data['label'],
            'prefix' => $prefix,
            'secret_hash' => hash('sha256', $secret),
            'expires_at' => $expiresAt,
        ]);
        $log->write('client.api_key_created', ['subject_id' => $request->user()->user_id, 'outcome' => 'completed']);

        return redirect()->route('settings.api-key.edit')
            ->with('status', 'api-key-created')->with('new_client_api_key', $secret);
    }

    public function destroy(Request $request, string $key, ActionLog $log): RedirectResponse
    {
        abort_unless(Str::isUuid($key), 404);
        $model = ClientApiKey::query()->where('user_id', $request->user()->user_id)->findOrFail($key);
        if ($model->revoked_at === null) {
            $model->forceFill(['revoked_at' => now()])->save();
            $log->write('client.api_key_revoked', ['subject_id' => $request->user()->user_id, 'outcome' => 'completed']);
        }

        return redirect()->route('settings.api-key.edit')->with('status', 'api-key-revoked');
    }

    public function destroyExpired(Request $request, ActionLog $log, ?string $key = null): RedirectResponse
    {
        abort_if($key !== null && ! Str::isUuid($key), 404);

        // Keep both ownership and expiry in the DELETE, not just in the UI.
        $query = ClientApiKey::query()->where('user_id', $request->user()->user_id)
            ->whereNotNull('expires_at')->where('expires_at', '<=', now());
        if ($key !== null) {
            $query->whereKey($key);
        }

        $deleted = $query->delete();
        abort_if($key !== null && $deleted === 0, 404);
        if ($deleted > 0) {
            $log->write('client.api_keys_expired_deleted', [
                'subject_id' => $request->user()->user_id, 'outcome' => 'completed', 'rows' => $deleted,
            ]);
        }

        return redirect()->route('settings.api-key.edit')
            ->with('status', 'api-keys-expired-deleted')
            ->with('deleted_client_api_key_count', $deleted);
    }

    public function destroyRevoked(Request $request, ActionLog $log, ?string $key = null): RedirectResponse
    {
        abort_if($key !== null && ! Str::isUuid($key), 404);

        $query = ClientApiKey::query()->where('user_id', $request->user()->user_id)
            ->whereNotNull('revoked_at');
        if ($key !== null) {
            $query->whereKey($key);
        }

        $deleted = $query->delete();
        abort_if($key !== null && $deleted === 0, 404);
        if ($deleted > 0) {
            $log->write('client.api_keys_revoked_deleted', [
                'subject_id' => $request->user()->user_id, 'outcome' => 'completed', 'rows' => $deleted,
            ]);
        }

        return redirect()->route('settings.api-key.edit')
            ->with('status', 'api-keys-revoked-deleted')
            ->with('deleted_client_api_key_count', $deleted);
    }

    private function expiry(?string $value, ?string $timezone): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $instant = CarbonImmutable::parse($value, 'UTC');
        if ($timezone !== null) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/', $value)) {
                throw ValidationException::withMessages(['expires_at' => 'Enter a date and time in the displayed timezone.']);
            }

            $wallTimestamp = $instant->getTimestamp();
            $zone = new DateTimeZone($timezone);
            $transitions = $zone->getTransitions($wallTimestamp - 172800, $wallTimestamp + 172800);
            $offsets = $transitions === false ? [$zone->getOffset($instant)] : array_column($transitions, 'offset');
            $matches = [];
            foreach (array_unique($offsets) as $offset) {
                $candidate = CarbonImmutable::createFromTimestamp($wallTimestamp - $offset, 'UTC');
                if ($candidate->setTimezone($zone)->format('Y-m-d H:i:s') === $instant->format('Y-m-d H:i:s')) {
                    $matches[] = $candidate;
                }
            }
            if (count($matches) !== 1) {
                throw ValidationException::withMessages(['expires_at' => 'This clock time is skipped or repeated by a timezone change. Choose another time, or switch to UTC.']);
            }
            $instant = $matches[0];
        }

        if (! $instant->isFuture()) {
            throw ValidationException::withMessages(['expires_at' => 'The expiry must be in the future.']);
        }

        return $instant->utc();
    }
}
