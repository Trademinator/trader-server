<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Security\PasswordCredentialRotation;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('settings.password', [
            'user' => $request->user(),
        ]);
    }

    public function update(Request $request, PasswordCredentialRotation $rotation): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Rules\Password::defaults(), 'confirmed'],
        ]);

        $rotation->rotate($request->user(), $validated['password']);
        $request->session()->forget('auth.password_confirmed_at');

        return back()->with('status', 'password-updated');
    }
}
