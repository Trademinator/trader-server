<?php

namespace App\Http\Middleware;

use App\Domain\Operations\ActionContext;
use App\Models\ClientApiKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateClientApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('client.enabled')) {
            return $this->error('client_api_disabled', 'Client API is disabled.', 503);
        }

        $secret = $request->bearerToken();
        if (! is_string($secret) || strlen($secret) < 32 || strlen($secret) > 128
            || (! str_starts_with($secret, 'tmk_') && ! Str::isUuid($secret))) {
            return $this->error('unauthenticated', 'A valid Client API bearer token is required.', 401);
        }

        $prefix = substr($secret, 0, 12);
        $key = ClientApiKey::query()->with('user')->where('prefix', $prefix)->first();
        if ($key === null || ! hash_equals($key->secret_hash, hash('sha256', $secret)) || ! $key->active()) {
            return $this->error('unauthenticated', 'The Client API key is invalid, expired, or revoked.', 401);
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

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
