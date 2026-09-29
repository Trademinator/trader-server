<?php

use App\Domain\Operations\AccessStatistics;
use App\Domain\Operations\GeoLocation;
use App\Models\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

it('looks up IPv4 and IPv6 in a local City MMDB without an HTTP service', function () {
    config(['operations.geoip_database' => base_path('tests/Fixtures/geoip/GeoIP2-City-Test.mmdb')]);
    $geo = app(GeoLocation::class);

    expect($geo->locate('81.2.69.142'))->toMatchArray(['country' => 'GB', 'city' => 'London']);
    expect($geo->locate('2001:218::'))->toMatchArray(['country' => 'JP', 'city' => 'Unknown']);
    expect($geo->status()['status'])->toBe('ready');
    expect($geo->locate('10.0.0.1'))->toBe(GeoLocation::UNKNOWN);
    expect($geo->locate('not-an-ip'))->toBe(GeoLocation::UNKNOWN);
});

it('keeps working with Unknown locations when the database is missing or corrupt', function () {
    config(['operations.geoip_database' => '/missing/GeoLite2-City.mmdb']);
    expect(app(GeoLocation::class)->locate('81.2.69.142'))->toBe(GeoLocation::UNKNOWN);
    expect(app(GeoLocation::class)->status()['status'])->toBe('missing');
    $path = tempnam(sys_get_temp_dir(), 'invalid-geoip-');
    file_put_contents($path, 'invalid database');
    try {
        config(['operations.geoip_database' => $path]);
        expect(app(GeoLocation::class)->locate('81.2.69.142'))->toBe(GeoLocation::UNKNOWN);
        expect(app(GeoLocation::class)->status()['status'])->toBe('unreadable');
    } finally {
        unlink($path);
    }
});

it('counts application requests and daily visitors without retaining IPs or URL secrets', function () {
    config(['operations.geoip_database' => base_path('tests/Fixtures/geoip/GeoIP2-City-Test.mmdb')]);
    $this->freezeTime();
    $this->withServerVariables(['REMOTE_ADDR' => '81.2.69.142']);

    $this->get('/?secret=PRIVATE_QUERY')->assertOk();
    $this->get('/?secret=OTHER_QUERY')->assertOk();
    $this->get('/up')->assertOk();

    $this->assertDatabaseHas('access_daily_stats', ['route' => 'home', 'requests' => 2, 'country' => 'GB', 'city' => 'London']);
    expect(DB::table('access_daily_stats')->count())->toBe(1);
    expect(DB::table('access_daily_visitors')->count())->toBe(1);
    $rows = json_encode([DB::table('access_daily_stats')->get(), DB::table('access_daily_visitors')->get()]);
    expect($rows)->not->toContain('81.2.69.142', 'PRIVATE_QUERY', 'OTHER_QUERY');
    $firstHash = DB::table('access_daily_visitors')->value('visitor_hash');
    $this->travel(1)->days();
    $this->get('/')->assertOk();
    expect(DB::table('access_daily_visitors')->where('visitor_hash', '!=', $firstHash)->count())->toBe(1);
});

it('ignores spoofed forwarding and geography headers from untrusted addresses', function () {
    config(['operations.geoip_database' => base_path('tests/Fixtures/geoip/GeoIP2-City-Test.mmdb')]);
    TrustProxies::at([]);

    $this->withServerVariables(['REMOTE_ADDR' => '81.2.69.142'])->withHeaders([
        'X-Forwarded-For' => '89.160.20.112', 'X-Trademinator-Country' => 'CA', 'X-Trademinator-City' => 'Fake City',
    ])->get('/')->assertOk();

    $this->assertDatabaseHas('access_daily_stats', ['country' => 'GB', 'city' => 'London']);
});

it('resolves the client IP through an explicitly trusted proxy', function () {
    config(['operations.geoip_database' => base_path('tests/Fixtures/geoip/GeoIP2-City-Test.mmdb')]);
    TrustProxies::at(['10.20.0.2']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.2'])->withHeaders(['X-Forwarded-For' => '89.160.20.112'])->get('/')->assertOk();

    $this->assertDatabaseHas('access_daily_stats', ['country' => 'SE', 'city' => 'Linköping']);
});

it('does not record new requests when statistics are disabled', function () {
    config(['operations.access_enabled' => false]);

    $this->get('/')->assertOk()->assertHeader('X-Trademinator-Trace');

    expect(DB::table('access_daily_stats')->count())->toBe(0);
});

it('prunes expired buckets and visitor hashes while retaining the boundary day', function () {
    config(['operations.retention_days' => 2]);
    $this->freezeTime();
    $request = Request::create('/', server: ['REMOTE_ADDR' => '81.2.69.142']);
    $request->setUserResolver(fn () => null);
    app(AccessStatistics::class)->record($request, 200, 5);
    $this->travel(1)->days();
    app(AccessStatistics::class)->record($request, 200, 5);
    $this->travel(1)->days();
    app(AccessStatistics::class)->record($request, 200, 5);

    $this->artisan('trademinator:prune-access-statistics')->assertSuccessful();

    expect(DB::table('access_daily_stats')->count())->toBe(2);
    expect(DB::table('access_daily_visitors')->count())->toBe(2);
    expect(DB::table('access_daily_stats')->min('day'))->toBe(now('UTC')->subDay()->toDateString());
});

it('reports geographic totals and filters the requested date range', function () {
    $this->freezeTime();
    config(['operations.geoip_database' => base_path('tests/Fixtures/geoip/GeoIP2-City-Test.mmdb')]);
    $request = Request::create('/', server: ['REMOTE_ADDR' => '81.2.69.142']);
    $request->setUserResolver(fn () => null);
    app(AccessStatistics::class)->record($request, 500, 100);
    $this->travel(3)->days();
    app(AccessStatistics::class)->record($request, 200, 25);
    config(['operations.access_enabled' => false]);
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);

    $response = $this->actingAs($owner)->get('/owner/access?days=1')->assertOk()->assertSee('London');

    $response->assertViewHas('totals', fn ($totals) => (int) $totals->requests === 1 && (int) $totals->server_errors === 0);
    $this->get('/owner/access?days=1000')->assertSessionHasErrors('days');
});
