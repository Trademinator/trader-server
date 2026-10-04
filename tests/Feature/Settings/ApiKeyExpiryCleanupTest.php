<?php

use App\Domain\Operations\ActionLog;
use App\Models\ClientApiKey;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(now()->startOfSecond());
});

afterEach(function () {
    $this->travelBack();
});

function createClientApiKeyForExpiryCleanup(User $user, array $attributes = []): ClientApiKey
{
    return ClientApiKey::query()->create([
        'user_id' => $user->user_id,
        'label' => 'Cleanup test key',
        'prefix' => 'tmk_'.Str::random(16),
        'secret_hash' => hash('sha256', Str::random(64)),
        ...$attributes,
    ]);
}

it('permanently deletes only the signed-in users expired keys including the expiry boundary', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $expired = createClientApiKeyForExpiryCleanup($user, ['expires_at' => now()->subSecond()]);
    $boundary = createClientApiKeyForExpiryCleanup($user, ['expires_at' => now()]);
    $expiredRevoked = createClientApiKeyForExpiryCleanup($user, [
        'expires_at' => now()->subDay(), 'revoked_at' => now()->subDays(2),
    ]);
    $preserved = [
        createClientApiKeyForExpiryCleanup($user, ['expires_at' => now()->addSecond()]),
        createClientApiKeyForExpiryCleanup($user),
        createClientApiKeyForExpiryCleanup($user, ['revoked_at' => now()->subDay()]),
        createClientApiKeyForExpiryCleanup($user, ['expires_at' => now()->addDay(), 'revoked_at' => now()->subDay()]),
        createClientApiKeyForExpiryCleanup($other, ['expires_at' => now()->subDay()]),
    ];
    $log = Mockery::spy(ActionLog::class);
    $this->instance(ActionLog::class, $log);

    $this->actingAs($user)->delete(route('settings.api-key.expired.destroy'))
        ->assertRedirect(route('settings.api-key.edit'))
        ->assertSessionHas('status', 'api-keys-expired-deleted')
        ->assertSessionHas('deleted_client_api_key_count', 3);

    foreach ([$expired, $boundary, $expiredRevoked] as $key) {
        $this->assertModelMissing($key);
    }
    foreach ($preserved as $key) {
        $this->assertModelExists($key);
    }
    expect(ClientApiKey::query()->count())->toBe(5);
    $log->shouldHaveReceived('write')->with('client.api_keys_expired_deleted', [
        'subject_id' => $user->user_id, 'outcome' => 'completed', 'rows' => 3,
    ])->once();

    $this->get(route('settings.api-key.edit'))->assertOk()->assertSee('Deleted 3 expired Client API keys.');
});

it('can delete one expired key without deleting another expired key', function () {
    $user = User::factory()->create();
    $one = createClientApiKeyForExpiryCleanup($user, ['label' => 'Expired desktop', 'expires_at' => now()->subDay()]);
    $two = createClientApiKeyForExpiryCleanup($user, ['expires_at' => now()->subDay()]);

    $this->actingAs($user)->delete(route('settings.api-key.expired.destroy', $one->getKey()))
        ->assertRedirect(route('settings.api-key.edit'))
        ->assertSessionHas('deleted_client_api_key_count', 1);

    $this->assertModelMissing($one);
    $this->assertModelExists($two);
    $this->get(route('settings.api-key.edit'))->assertOk()
        ->assertSee('Deleted 1 expired Client API key.')
        ->assertDontSee('Expired desktop');
});

it('rejects deletion of a selected key that is not owned and expired', function (string $kind) {
    $user = User::factory()->create();
    $owner = $kind === 'foreign' ? User::factory()->create() : $user;
    $attributes = match ($kind) {
        'active' => ['expires_at' => now()->addSecond()],
        'revoked only' => ['revoked_at' => now()->subDay()],
        'foreign' => ['expires_at' => now()->subDay()],
        default => [],
    };
    $key = createClientApiKeyForExpiryCleanup($owner, $attributes);

    $this->actingAs($user)->delete(route('settings.api-key.expired.destroy', $key->getKey()))
        ->assertNotFound();

    $this->assertModelExists($key);
    expect($key->refresh()->revoked_at?->toDateTimeString())
        ->toBe($kind === 'revoked only' ? now()->subDay()->toDateTimeString() : null);
})->with(['active', 'no expiry', 'revoked only', 'foreign']);

it('rejects missing and malformed selected key identifiers without falling back to bulk deletion', function (string $kind) {
    $user = User::factory()->create();
    $key = createClientApiKeyForExpiryCleanup($user, ['expires_at' => now()->subDay()]);
    $identifier = $kind === 'missing' ? (string) Str::uuid() : 'not-a-uuid';

    $this->actingAs($user)->delete('/settings/api-key/expired/'.$identifier)->assertNotFound();

    $this->assertModelExists($key);
})->with(['missing', 'malformed']);

it('allows a repeated bulk cleanup as a safe no-op', function () {
    $user = User::factory()->create();
    $expired = createClientApiKeyForExpiryCleanup($user, ['expires_at' => now()->subDay()]);
    $active = createClientApiKeyForExpiryCleanup($user);
    $this->actingAs($user)->delete(route('settings.api-key.expired.destroy'))
        ->assertRedirect(route('settings.api-key.edit'))->assertSessionHas('deleted_client_api_key_count', 1);

    $this->delete(route('settings.api-key.expired.destroy'))
        ->assertRedirect(route('settings.api-key.edit'))->assertSessionHas('deleted_client_api_key_count', 0);

    $this->assertModelMissing($expired);
    $this->assertModelExists($active);
    $this->get(route('settings.api-key.edit'))->assertOk()->assertSee('No expired Client API keys to delete.');
});

it('requires authentication to delete expired keys', function (bool $single) {
    $key = createClientApiKeyForExpiryCleanup(User::factory()->create(), ['expires_at' => now()->subDay()]);

    $this->delete(route('settings.api-key.expired.destroy', $single ? ['key' => $key->getKey()] : []))
        ->assertRedirect(route('login'));

    $this->assertModelExists($key);
})->with(['bulk' => [false], 'single' => [true]]);

it('requires a verified email to delete expired keys', function (bool $single) {
    $user = User::factory()->unverified()->create();
    $key = createClientApiKeyForExpiryCleanup($user, ['expires_at' => now()->subDay()]);

    $this->actingAs($user)->delete(route('settings.api-key.expired.destroy', $single ? ['key' => $key->getKey()] : []))
        ->assertRedirect(route('verification.notice'));

    $this->assertModelExists($key);
})->with(['bulk' => [false], 'single' => [true]]);

it('shows cleanup actions for expired keys and retains revocation for active keys', function () {
    $user = User::factory()->create();
    $expired = createClientApiKeyForExpiryCleanup($user, ['label' => 'Expired laptop', 'expires_at' => now()]);
    $active = createClientApiKeyForExpiryCleanup($user, ['label' => 'Active desktop']);
    $foreign = createClientApiKeyForExpiryCleanup(User::factory()->create(), ['label' => 'Other users key', 'expires_at' => now()->subDay()]);

    $this->actingAs($user)->get(route('settings.api-key.edit'))->assertOk()
        ->assertSee('Delete expired keys')
        ->assertSee('action="'.route('settings.api-key.expired.destroy').'"', false)
        ->assertSee('action="'.route('settings.api-key.expired.destroy', $expired->getKey()).'"', false)
        ->assertSee('Delete expired key: Expired laptop')
        ->assertSee('action="'.route('settings.api-key.destroy', $active->getKey()).'"', false)
        ->assertDontSee('action="'.route('settings.api-key.expired.destroy', $active->getKey()).'"', false)
        ->assertDontSee('action="'.route('settings.api-key.destroy', $expired->getKey()).'"', false)
        ->assertDontSee($foreign->label)
        ->assertSee('window.confirm', false);
});

it('also offers deletion when a revoked key has expired', function () {
    $user = User::factory()->create();
    $key = createClientApiKeyForExpiryCleanup($user, [
        'expires_at' => now()->subDay(), 'revoked_at' => now()->subDays(2),
    ]);

    $this->actingAs($user)->get(route('settings.api-key.edit'))->assertOk()
        ->assertSee('Delete expired keys')
        ->assertSee('action="'.route('settings.api-key.expired.destroy', $key->getKey()).'"', false)
        ->assertSee('Revoked');
});

it('does not show cleanup actions when the user has no expired keys', function (bool $withKeys) {
    $user = User::factory()->create();
    if ($withKeys) {
        createClientApiKeyForExpiryCleanup($user, ['expires_at' => now()->addSecond()]);
        createClientApiKeyForExpiryCleanup($user);
        createClientApiKeyForExpiryCleanup($user, ['revoked_at' => now()->subDay()]);
    }
    createClientApiKeyForExpiryCleanup(User::factory()->create(), ['expires_at' => now()->subDay()]);

    $this->actingAs($user)->get(route('settings.api-key.edit'))->assertOk()
        ->assertDontSee('Delete expired keys')
        ->assertDontSee('action="'.route('settings.api-key.expired.destroy').'"', false);
})->with(['empty list' => [false], 'non-expired keys' => [true]]);

it('uses the full available width for the api key table without widening other settings forms', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings.api-key.edit'))->assertOk()
        ->assertSee('class="mt-5 w-full"', false)
        ->assertDontSee('class="mt-5 w-full max-w-lg"', false)
        ->assertSee('class="min-w-0 flex-1 self-stretch max-md:pt-6"', false)
        ->assertSee('class="mt-8 w-full overflow-x-auto"', false)
        ->assertSee('class="w-full text-sm"', false);

    $this->get(route('settings.profile.edit'))->assertOk()
        ->assertSee('class="mt-5 w-full max-w-lg"', false);
});

it('keeps cleanup behind the verified throttled delete route', function () {
    $route = Route::getRoutes()->getByName('settings.api-key.expired.destroy');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toBe(['DELETE'])
        ->and($route->gatherMiddleware())->toContain('web', 'auth', 'verified', 'throttle:30,1');
});
