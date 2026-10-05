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

final class ConfirmFailedJobPassword extends RequirePassword
{
    /**
     * Preserve the selected job and action while confirming the password in place.
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
        $discard = $request->routeIs('owner.failed-jobs.destroy');

        return response()->view('auth.confirm-password', [
            'formAction' => route($discard ? 'owner.failed-jobs.destroy' : 'owner.failed-jobs.retry', $request->route('job')),
            'formFields' => $discard ? ['_method' => 'DELETE'] : [],
            'confirmLabel' => $discard ? __('Confirm and discard failed job') : __('Confirm and requeue failed job'),
        ], $errors->isEmpty() ? 200 : 422);
    }
}
