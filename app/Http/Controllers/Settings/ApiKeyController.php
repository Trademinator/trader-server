<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApiKeyController extends Controller
{
    public function edit(Request $request): View
    {
        return view('settings.api-key', [
            'user' => $request->user(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $currentApiKey = (string) ($request->user()->api_key ?? '');

        $validated = $request->validate([
            'current_api_key' => [
                Rule::requiredIf($currentApiKey !== ''),
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($currentApiKey): void {
                    if ($currentApiKey !== '' && ! hash_equals($currentApiKey, (string) $value)) {
                        $fail(__('The current API key is incorrect.'));
                    }
                },
            ],
            'api_key' => ['required', 'uuid', 'confirmed', 'different:current_api_key'],
        ]);

        $request->user()->update([
            'api_key' => $validated['api_key'],
        ]);

        return back()->with('status', 'api-key-updated');
    }
}
