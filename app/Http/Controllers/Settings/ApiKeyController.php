<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class ApiKeyController extends Controller
{
    public function edit(Request $request): View
    {
        return view('settings.apikey', [
            'user' => $request->user(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_apikey' => ['required', 'current_apikey'],
            'apikey' => ['required', 'confirmed'],
        ]);

        $request->user()->update([
            'apikey' => $validated['apikey'],
        ]);

        return back()->with('status', 'apikey-updated');
    }
}
