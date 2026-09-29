<?php

namespace App\Domain\Operations;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccessStatistics
{
    public function __construct(private GeoLocation $geo) {}

    public function record(Request $request, int $status, int $duration): void
    {
        if (! config('operations.access_enabled') || $request->is('up')) {
            return;
        }
        $date = now('UTC')->toDateString();
        $place = $this->geo->locate($request->ip());
        // Static route templates bound cardinality and never persist path values, tokens or query strings.
        $route = $request->route()?->getName() ?? $request->route()?->uri() ?? 'unmatched';
        $dimensions = ['day' => $date, 'route' => mb_substr($route, 0, 160),
            'method' => in_array($request->method(), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true) ? $request->method() : 'OTHER',
            'status_code' => $status, 'authenticated' => $request->user() !== null, ...$place];
        $key = hash('sha256', json_encode($dimensions, JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($key, $dimensions, $duration, $request, $date, $place): void {
            DB::table('access_daily_stats')->insertOrIgnore(['bucket_id' => $key, ...$dimensions,
                'requests' => 0, 'duration_ms' => 0]);
            DB::table('access_daily_stats')->where('bucket_id', $key)->incrementEach(['requests' => 1, 'duration_ms' => max(0, $duration)]);
            if (config('app.key') && filter_var($request->ip(), FILTER_VALIDATE_IP)) {
                $visitor = hash_hmac('sha256', $date.'|'.$request->ip(), (string) config('app.key'));
                DB::table('access_daily_visitors')->insertOrIgnore(['day' => $date, 'visitor_hash' => $visitor, ...$place]);
            }
        });
        if ($request->user() !== null) {
            User::query()->whereKey($request->user()->getAuthIdentifier())
                ->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subMinutes(5)))
                ->update(['last_seen_at' => now()]);
        }
    }
}
