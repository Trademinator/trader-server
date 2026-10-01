<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Operations\ActionLog;
use App\Http\Controllers\Controller;
use App\Models\ClientApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiKeyController extends Controller
{
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
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $active = ClientApiKey::query()->where('user_id', $request->user()->user_id)
            ->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();
        abort_if($active >= config('client.max_keys'), 422, 'Revoke or let an existing Client API key expire before creating another.');

        do {
            $secret = 'tmk_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $prefix = substr($secret, 0, 12);
        } while (ClientApiKey::query()->where('prefix', $prefix)->exists());

        $key = ClientApiKey::query()->create([
            'user_id' => $request->user()->user_id,
            'label' => $data['label'],
            'prefix' => $prefix,
            'secret_hash' => hash('sha256', $secret),
            'expires_at' => $data['expires_at'] ?? null,
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
}
