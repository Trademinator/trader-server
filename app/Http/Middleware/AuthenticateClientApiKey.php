<?php

namespace App\Http\Middleware;

use App\Domain\Operations\ActionContext;
use App\Models\ClientApiKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateClientApiKey
{
    private const FAILED_ATTEMPTS_PER_MINUTE = 30;

    private const FAILED_ATTEMPTS_PER_HOUR = 300;

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('client.enabled')) {
            return $this->error('client_api_disabled', 'Client API is disabled.', 503);
        }

        if (($limited = $this->failedAttemptLimit($request)) !== null) {
            return $limited;
        }

        $secret = $request->bearerToken();
        if (! is_string($secret) || strlen($secret) < 32 || strlen($secret) > 128
            || (! str_starts_with($secret, 'tmk_') && ! Str::isUuid($secret))) {
            return $this->authenticationFailure($request, 'A valid Client API bearer token is required.');
        }

        $secretHash = hash('sha256', $secret);
        $key = ClientApiKey::query()->with('user')->where('secret_hash', $secretHash)->first();
        if ($key === null || ! hash_equals($key->secret_hash, $secretHash) || ! $key->active()) {
            return $this->authenticationFailure($request, 'The Client API key is invalid, expired, or revoked.');
        }
        if ($key->user === null || $key->user->suspended_at !== null || ! $key->user->hasVerifiedEmail()) {
            return $this->error('account_unavailable', 'The account is not available for Client API access.', 403);
        }

        $request->setUserResolver(fn () => $key->user);
        $request->attributes->set('client_api_key_id', $key->getKey());
        app(ActionContext::class)->actor($key->user_id);

        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Trademinator-API-Version', '1');

        return $response;
    }

    private function failedAttemptLimit(Request $request): ?JsonResponse
    {
        [$minuteKey, $hourKey] = $this->failedAttemptKeys($request);
        $retryAfter = 0;
        if (RateLimiter::tooManyAttempts($minuteKey, self::FAILED_ATTEMPTS_PER_MINUTE)) {
            $retryAfter = max($retryAfter, RateLimiter::availableIn($minuteKey));
        }
        if (RateLimiter::tooManyAttempts($hourKey, self::FAILED_ATTEMPTS_PER_HOUR)) {
            $retryAfter = max($retryAfter, RateLimiter::availableIn($hourKey));
        }
        if ($retryAfter === 0) {
            return null;
        }

        return $this->error('authentication_rate_limited',
            'Too many failed Client API authentication attempts. Try again later.', 429)
            ->header('Retry-After', (string) $retryAfter);
    }

    private function authenticationFailure(Request $request, string $message): JsonResponse
    {
        [$minuteKey, $hourKey] = $this->failedAttemptKeys($request);
        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($hourKey, 3600);

        return $this->error('unauthenticated', $message, 401);
    }

    /** @return array{0: string, 1: string} */
    private function failedAttemptKeys(Request $request): array
    {
        $ip = $request->ip() ?? 'unknown';

        return ['client-api-auth-fail-minute:'.$ip, 'client-api-auth-fail-hour:'.$ip];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
