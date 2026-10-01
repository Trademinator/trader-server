<?php

use App\Models\ClientApiKey;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\Ticker;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

it('saves the authenticated users IANA timezone without changing another account or the application timezone', function () {
    $user = User::factory()->create();
    $other = User::factory()->create(['timezone' => 'Asia/Tokyo']);

    $this->actingAs($user)->put('/settings/profile', [
        'name' => $user->name, 'email' => $user->email, 'timezone' => 'America/Toronto', 'user_id' => $other->user_id,
    ])->assertRedirect('/settings/profile')->assertSessionHasNoErrors();

    expect($user->refresh()->timezone)->toBe('America/Toronto');
    expect($other->refresh()->timezone)->toBe('Asia/Tokyo');
    expect(config('app.timezone'))->toBe('UTC');
    $this->get('/settings/profile')->assertSee('value="America/Toronto" selected', false);
});

it('rejects an invalid timezone without changing the saved profile', function () {
    $user = User::factory()->create(['timezone' => 'America/Toronto']);

    $this->actingAs($user)->put('/settings/profile', [
        'name' => 'Should not be saved', 'email' => $user->email, 'timezone' => 'Mars/Olympus',
    ])->assertSessionHasErrors(['timezone' => 'The timezone field must be a valid timezone.']);

    expect($user->fresh()->timezone)->toBe('America/Toronto');
    expect($user->fresh()->name)->toBe($user->name);
});

it('supports automatic browser time and preserves a saved timezone when an older form omits it', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Kathmandu']);
    $this->actingAs($user)->put('/settings/profile', ['name' => $user->name, 'email' => $user->email])
        ->assertSessionHasNoErrors();
    expect($user->refresh()->timezone)->toBe('Asia/Kathmandu');

    $this->put('/settings/profile', ['name' => $user->name, 'email' => $user->email, 'timezone' => ''])
        ->assertSessionHasNoErrors();
    expect($user->refresh()->timezone)->toBeNull();
    $this->get('/dashboard')->assertSee('data-time-display', false)->assertSee('data-timezone=""', false);
});

it('requires authentication to change the timezone', function () {
    $this->put('/settings/profile', ['timezone' => 'America/Toronto'])->assertRedirect('/login');
});

it('renders local timestamps with the offset at that instant and preserves the original UTC value', function (string $utc, string $timezone, string $expected) {
    $this->actingAs(User::factory()->create(['timezone' => $timezone]));
    $date = Carbon::parse($utc)->utc();

    $this->blade('<x-display-time :value="$date" />', ['date' => $date])
        ->assertSee($expected)->assertSee('datetime="'.$date->toISOString().'"', false);

    expect($date->timezoneName)->toBe('UTC');
})->with([
    'before spring jump' => ['2026-03-08T06:59:00Z', 'America/Toronto', '2026-03-08 01:59:00 UTC-05:00'],
    'after spring jump' => ['2026-03-08T07:00:00Z', 'America/Toronto', '2026-03-08 03:00:00 UTC-04:00'],
    'first repeated hour' => ['2026-11-01T05:30:00Z', 'America/Toronto', '2026-11-01 01:30:00 UTC-04:00'],
    'second repeated hour' => ['2026-11-01T06:30:00Z', 'America/Toronto', '2026-11-01 01:30:00 UTC-05:00'],
    'fractional offset' => ['2026-10-01T20:00:00Z', 'Asia/Kathmandu', '2026-10-02 01:45:00 UTC+05:45'],
]);

it('distinguishes milliseconds from seconds and leaves missing timestamps unavailable', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'America/Toronto']));

    $this->blade('<x-display-time :value="1790834400123" unit="milliseconds" />')
        ->assertSee('2026-10-01 02:00:00 UTC-04:00')->assertSee('2026-10-01T06:00:00.123000Z');
    $this->blade('<x-display-time :value="1790834400" unit="seconds" />')
        ->assertSee('2026-10-01 02:00:00 UTC-04:00');
    $this->blade('<x-display-time :value="null" fallback="Never" />')
        ->assertSee('Never')->assertDontSee('<time', false);
});

it('renders account dates using the viewers timezone and offers the switch on owner pages', function () {
    $owner = User::factory()->create(['timezone' => 'America/Toronto']);
    config(['operations.owner_uuid' => $owner->user_id]);
    $target = User::factory()->create(['timezone' => 'Asia/Tokyo', 'last_login_at' => '2026-10-01 02:00:00']);

    $this->actingAs($owner)->get(route('owner.users.show', $target))
        ->assertSee('2026-09-30 22:00:00 UTC-04:00')->assertSee('data-time-display', false)
        ->assertSee('data-time-mode="utc"', false);

    expect($target->fresh()->last_login_at->format('Y-m-d H:i:s'))->toBe('2026-10-01 02:00:00');
});

it('converts a future local API key expiry to UTC before validation and storage', function (string $timezone, string $local, string $expected) {
    $this->travelTo('2026-10-01 12:00:00 UTC');
    $user = User::factory()->create(['timezone' => 'America/Toronto']);

    $this->actingAs($user)->post('/settings/api-key', [
        'label' => 'Local expiry', 'expires_at' => $local, 'expires_timezone' => $timezone,
    ])->assertRedirect('/settings/api-key')->assertSessionHasNoErrors();

    $key = ClientApiKey::query()->where('user_id', $user->user_id)->firstOrFail();
    expect($key->expires_at->utc()->format('Y-m-d H:i:s'))->toBe($expected);
    $this->get('/settings/api-key')->assertSee('data-time-input', false)->assertSee('data-time-display', false);
})->with([
    'local time which looks past in UTC' => ['America/Toronto', '2026-10-01T09:00', '2026-10-01 13:00:00'],
    'quarter hour offset' => ['Asia/Kathmandu', '2026-10-02T09:00', '2026-10-02 03:15:00'],
    'UTC mode' => ['UTC', '2026-10-01T13:00', '2026-10-01 13:00:00'],
    'half hour offset' => ['America/St_Johns', '2026-10-02T09:00', '2026-10-02 11:30:00'],
]);

it('rejects API key expiry clock times skipped or repeated by daylight saving', function (string $expiry) {
    $this->travelTo('2026-01-01 00:00:00 UTC');
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/api-key', [
        'label' => 'Ambiguous expiry', 'expires_at' => $expiry, 'expires_timezone' => 'America/Toronto',
    ])->assertSessionHasErrors(['expires_at' => 'This clock time is skipped or repeated by a timezone change. Choose another time, or switch to UTC.']);

    $this->assertDatabaseCount('client_api_keys', 0);
})->with(['2026-03-08T02:30', '2026-11-01T01:30']);

it('rejects a local API key expiry that is already past in UTC', function () {
    $this->travelTo('2026-10-01 12:00:00 UTC');
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/api-key', [
        'label' => 'Past expiry', 'expires_at' => '2026-10-01T15:00', 'expires_timezone' => 'Asia/Tokyo',
    ])->assertSessionHasErrors(['expires_at' => 'The expiry must be in the future.']);

    $this->assertDatabaseCount('client_api_keys', 0);
});

it('keeps UTC day counts intact while showing their exact boundaries in local time', function () {
    $this->travelTo('2026-10-01 12:00:00 UTC');
    $owner = User::factory()->create(['timezone' => 'America/Toronto']);
    config(['operations.owner_uuid' => $owner->user_id]);
    DB::table('access_daily_stats')->insert([
        'day' => '2026-09-30', 'country' => 'CA', 'region' => 'ON', 'city' => 'Toronto',
        'bucket_id' => str_repeat('a', 64), 'route' => 'dashboard', 'method' => 'GET', 'status_code' => 200, 'requests' => 7,
        'duration_ms' => 70, 'authenticated' => true,
    ]);

    $this->actingAs($owner)->get('/owner/access')->assertSee('2026-09-29 20:00 UTC-04:00')
        ->assertSee('2026-09-30 20:00 UTC-04:00');
});

it('preserves an explicit offset from a legacy API key form', function () {
    $this->travelTo('2026-10-01 12:00:00 UTC');
    $user = User::factory()->create(['timezone' => 'America/Toronto']);

    $this->actingAs($user)->post('/settings/api-key', [
        'label' => 'Offset expiry', 'expires_at' => '2026-10-01T15:00:00+02:00',
    ])->assertRedirect('/settings/api-key')->assertSessionHasNoErrors();

    expect($user->clientApiKeys()->firstOrFail()->expires_at->format('Y-m-d H:i:s'))->toBe('2026-10-01 13:00:00');
});

it('rejects an invalid expiry timezone without creating a key', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/api-key', [
        'label' => 'Invalid zone', 'expires_at' => '2030-10-01T15:00', 'expires_timezone' => 'Mars/Olympus',
    ])->assertSessionHasErrors(['expires_timezone' => 'The expires timezone field must be a valid timezone.']);

    $this->assertDatabaseCount('client_api_keys', 0);
});

it('localizes stale collection warnings in the dashboard AJAX fragment', function () {
    $this->travelTo('2026-10-01 12:00:00 UTC');
    $owner = User::factory()->create(['timezone' => 'America/Toronto']);
    config(['operations.owner_uuid' => $owner->user_id]);
    $signal = MarketSignal::factory()->create();
    $market = $signal->market;
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    MarketSubscription::query()->create(['user_id' => $owner->getKey(), 'market_id' => $market->getKey(), 'active' => true]);
    Ticker::query()->create([
        'exchange' => $market->exchange->class, 'symbol' => $market->symbol, 'period' => '1m',
        'microtimestamp' => 1790848800000,
        'payload' => json_encode(['open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '10']),
    ]);

    $response = $this->actingAs($owner)->getJson('/dashboard');

    $response->assertOk();
    expect($response->json('html'))->toContain('Last valid candle closed at', '2026-10-01 06:01:00 UTC-04:00', '2026-10-01T10:01:00.000000Z');
});
