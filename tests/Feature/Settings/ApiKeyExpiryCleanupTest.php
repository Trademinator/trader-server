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

function clientApiKeyCleanupButton(string $html, string $id): DOMElement
{
    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $button = $document->getElementById($id);
    expect($button)->toBeInstanceOf(DOMElement::class);

    return $button;
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

it('requires authentication to delete inactive keys', function (bool $single, string $kind) {
    $key = createClientApiKeyForExpiryCleanup(User::factory()->create(), ['expires_at' => now()->subDay(), 'revoked_at' => now()->subDay()]);

    $this->delete(route('settings.api-key.'.$kind.'.destroy', $single ? ['key' => $key->getKey()] : []))
        ->assertRedirect(route('login'));

    $this->assertModelExists($key);
})->with(['bulk' => [false], 'single' => [true]])->with(['expired', 'revoked']);

it('requires a verified email to delete inactive keys', function (bool $single, string $kind) {
    $user = User::factory()->unverified()->create();
    $key = createClientApiKeyForExpiryCleanup($user, ['expires_at' => now()->subDay(), 'revoked_at' => now()->subDay()]);

    $this->actingAs($user)->delete(route('settings.api-key.'.$kind.'.destroy', $single ? ['key' => $key->getKey()] : []))
        ->assertRedirect(route('verification.notice'));

    $this->assertModelExists($key);
})->with(['bulk' => [false], 'single' => [true]])->with(['expired', 'revoked']);

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

it('keeps expired cleanup visible but disabled when the user has no expired keys', function (bool $withKeys) {
    $user = User::factory()->create();
    if ($withKeys) {
        createClientApiKeyForExpiryCleanup($user, ['expires_at' => now()->addSecond()]);
        createClientApiKeyForExpiryCleanup($user);
        createClientApiKeyForExpiryCleanup($user, ['revoked_at' => now()->subDay()]);
    }
    createClientApiKeyForExpiryCleanup(User::factory()->create(), ['expires_at' => now()->subDay()]);

    $response = $this->actingAs($user)->get(route('settings.api-key.edit'))->assertOk()
        ->assertSee('Delete expired keys (0)')
        ->assertSee('action="'.route('settings.api-key.expired.destroy').'"', false)
        ->assertSee('Delete revoked keys ('.($withKeys ? 1 : 0).')');

    expect(clientApiKeyCleanupButton($response->getContent(), 'delete-expired-keys')->hasAttribute('disabled'))->toBeTrue()
        ->and(clientApiKeyCleanupButton($response->getContent(), 'delete-revoked-keys')->hasAttribute('disabled'))->toBe(! $withKeys);
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

it('keeps cleanup behind the verified throttled delete route', function (string $kind) {
    $route = Route::getRoutes()->getByName('settings.api-key.'.$kind.'.destroy');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toBe(['DELETE'])
        ->and($route->gatherMiddleware())->toContain('web', 'auth', 'verified', 'throttle:30,1');
})->with(['expired', 'revoked']);

it('enables expired cleanup at the expiry boundary without counting another users keys', function () {
    $user = User::factory()->create();
    createClientApiKeyForExpiryCleanup($user, ['expires_at' => now()]);
    createClientApiKeyForExpiryCleanup($user, ['expires_at' => now()->addSecond()]);
    createClientApiKeyForExpiryCleanup(User::factory()->create(), [
        'expires_at' => now()->subDay(), 'revoked_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($user)->get(route('settings.api-key.edit'))->assertOk()
        ->assertSee('Delete expired keys (1)')
        ->assertSee('Delete revoked keys (0)');

    expect(clientApiKeyCleanupButton($response->getContent(), 'delete-expired-keys')->hasAttribute('disabled'))->toBeFalse()
        ->and(clientApiKeyCleanupButton($response->getContent(), 'delete-revoked-keys')->hasAttribute('disabled'))->toBeTrue();
});

it('offers individual deletion of revoked keys without waiting for expiry', function (bool $hasFutureExpiry) {
    $user = User::factory()->create();
    $key = createClientApiKeyForExpiryCleanup($user, [
        'label' => 'Revoked desktop',
        'revoked_at' => now()->subDay(),
        'expires_at' => $hasFutureExpiry ? now()->addDay() : null,
    ]);

    $response = $this->actingAs($user)->get(route('settings.api-key.edit'))->assertOk()
        ->assertSee('Delete expired keys (0)')
        ->assertSee('Delete revoked keys (1)')
        ->assertSee('Delete revoked key: Revoked desktop')
        ->assertSee('action="'.route('settings.api-key.revoked.destroy', $key->getKey()).'"', false)
        ->assertDontSee('action="'.route('settings.api-key.destroy', $key->getKey()).'"', false);

    expect(clientApiKeyCleanupButton($response->getContent(), 'delete-revoked-keys')->hasAttribute('disabled'))->toBeFalse();
})->with(['no expiry' => [false], 'future expiry' => [true]]);

it('permanently deletes only the signed-in users revoked keys regardless of expiry', function () {
    $user = User::factory()->create();
    $revoked = [
        createClientApiKeyForExpiryCleanup($user, ['revoked_at' => now()->subDay()]),
        createClientApiKeyForExpiryCleanup($user, ['revoked_at' => now()->subDay(), 'expires_at' => now()->addDay()]),
        createClientApiKeyForExpiryCleanup($user, ['revoked_at' => now()->subDay(), 'expires_at' => now()->subDay()]),
    ];
    $preserved = [
        createClientApiKeyForExpiryCleanup($user),
        createClientApiKeyForExpiryCleanup($user, ['expires_at' => now()->addSecond()]),
        createClientApiKeyForExpiryCleanup($user, ['expires_at' => now()->subDay()]),
        createClientApiKeyForExpiryCleanup(User::factory()->create(), ['revoked_at' => now()->subDay()]),
    ];
    $log = Mockery::spy(ActionLog::class);
    $this->instance(ActionLog::class, $log);

    $this->actingAs($user)->delete(route('settings.api-key.revoked.destroy'))
        ->assertRedirect(route('settings.api-key.edit'))
        ->assertSessionHas('status', 'api-keys-revoked-deleted')
        ->assertSessionHas('deleted_client_api_key_count', 3);

    foreach ($revoked as $key) {
        $this->assertModelMissing($key);
    }
    foreach ($preserved as $key) {
        $this->assertModelExists($key);
    }
    $log->shouldHaveReceived('write')->with('client.api_keys_revoked_deleted', [
        'subject_id' => $user->user_id, 'outcome' => 'completed', 'rows' => 3,
    ])->once();
    $this->get(route('settings.api-key.edit'))->assertOk()->assertSee('Deleted 3 revoked Client API keys.');

    $this->delete(route('settings.api-key.revoked.destroy'))
        ->assertRedirect(route('settings.api-key.edit'))->assertSessionHas('deleted_client_api_key_count', 0);
    $this->get(route('settings.api-key.edit'))->assertOk()->assertSee('No revoked Client API keys to delete.');
    foreach ($preserved as $key) {
        $this->assertModelExists($key);
    }
    $log->shouldHaveReceived('write')->with('client.api_keys_revoked_deleted', Mockery::any())->once();
});

it('deletes one revoked key without deleting other revoked keys', function () {
    $user = User::factory()->create();
    $key = createClientApiKeyForExpiryCleanup($user, ['label' => 'Old revoked key', 'revoked_at' => now()->subDay()]);
    $other = createClientApiKeyForExpiryCleanup($user, ['revoked_at' => now()->subDay()]);

    $this->actingAs($user)->delete(route('settings.api-key.revoked.destroy', $key->getKey()))
        ->assertRedirect(route('settings.api-key.edit'))
        ->assertSessionHas('status', 'api-keys-revoked-deleted')
        ->assertSessionHas('deleted_client_api_key_count', 1);

    $this->assertModelMissing($key);
    $this->assertModelExists($other);
    $this->get(route('settings.api-key.edit'))->assertOk()
        ->assertSee('Deleted 1 revoked Client API key.')
        ->assertDontSee('Old revoked key');
});

it('rejects selected revoked cleanup for active expired-only foreign missing and malformed keys', function (string $kind) {
    $user = User::factory()->create();
    $preserved = createClientApiKeyForExpiryCleanup($user, ['revoked_at' => now()->subDay()]);
    $owner = $kind === 'foreign' ? User::factory()->create() : $user;
    $attributes = match ($kind) {
        'future expiry' => ['expires_at' => now()->addDay()],
        'expired only' => ['expires_at' => now()->subDay()],
        'foreign' => ['revoked_at' => now()->subDay()],
        default => [],
    };
    $key = createClientApiKeyForExpiryCleanup($owner, $attributes);
    $identifier = match ($kind) {
        'missing' => (string) Str::uuid(),
        'malformed' => 'not-a-uuid',
        default => $key->getKey(),
    };

    $this->actingAs($user)->delete('/settings/api-key/revoked/'.$identifier)->assertNotFound();

    $this->assertModelExists($key);
    $this->assertModelExists($preserved);
})->with(['active without expiry', 'future expiry', 'expired only', 'foreign', 'missing', 'malformed']);
