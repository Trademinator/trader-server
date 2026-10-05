<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

final class ConfirmHistoryRecoveryPassword extends RequirePassword
{
    /**
     * Confirm the repair POST in place so its options survive password confirmation.
     *
     * @param  Request  $request
     * @param  Closure(Request): (Response)  $next
     * @param  string|null  $redirectToRoute
     * @param  string|int|null  $passwordTimeoutSeconds
     */
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null): Response
    {
        $passwordTimeoutSeconds ??= config('auth.password_timeout') ?: 10800;

        if (! $this->shouldConfirmPassword($request, $passwordTimeoutSeconds)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
        }

        $request->validate(['retry_unavailable' => ['sometimes', 'boolean']]);
        $errors = new MessageBag;

        if ($request->has('password')) {
            $validator = Validator::make($request->only('password'), [
                'password' => ['bail', 'required', 'string', 'current_password:web'],
            ]);

            if ($validator->passes()) {
                $request->session()->passwordConfirmed();

                return $next($request);
            }

            $errors = $validator->errors();
        }

        View::share('errors', (new ViewErrorBag)->put('default', $errors));

        return response()->view('auth.confirm-password', [
            'formAction' => route('owner.history-recovery.store', $request->route('market')),
            'formFields' => ['retry_unavailable' => $request->boolean('retry_unavailable') ? '1' : '0'],
            'confirmLabel' => __('Confirm and repair history'),
        ], $errors->isEmpty() ? 200 : 422);
    }
}
