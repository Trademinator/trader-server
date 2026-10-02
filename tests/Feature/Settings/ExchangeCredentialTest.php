<?php

use App\Models\Exchange;
use App\Models\ExchangeCredential;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function settingsCredentialExchange(): Exchange
{
    return Exchange::query()->create(['class' => 'coinbase', 'name' => 'Coinbase', 'config' => '{}']);
}

it('requires authentication for exchange credential settings and mutations', function () {
    $exchange = settingsCredentialExchange();

    $this->get(route('settings.exchange-keys.index'))->assertRedirect(route('login'));
    $this->put(route('settings.exchange-keys.update', $exchange), [])->assertRedirect(route('login'));
    $this->delete(route('settings.exchange-keys.destroy', $exchange))->assertRedirect(route('login'));
    $this->assertDatabaseCount('exchange_credentials', 0);
});

it('encrypts saved keys and never returns them in the form or model serialization', function () {
    $user = User::factory()->create();
    $exchange = settingsCredentialExchange();
    $values = ['apiKey' => 'organizations/example/apiKeys/example', 'secret' => 'FIRST\\nSECOND'];

    $this->actingAs($user)->put(route('settings.exchange-keys.update', $exchange), [
        'credentials' => $values, 'read_only_confirmed' => '1', 'user_id' => 'not-the-user',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $credential = $user->exchangeCredentials()->sole();
    expect($credential->credentials)->toBe(['apiKey' => $values['apiKey'], 'secret' => "FIRST\nSECOND"]);
    expect(DB::table('exchange_credentials')->value('credentials'))->not->toContain($values['apiKey'], 'FIRST', 'SECOND');
    expect($credential->toArray())->not->toHaveKey('credentials');
    expect($credential->is_shared)->toBeFalse();
    $this->get(route('settings.exchange-keys.index', ['exchange' => $exchange->exchange_id]))
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('READ-ONLY KEYS ONLY')
        ->assertSee('Trademinator cannot automatically verify the permissions you selected at the exchange.')
        ->assertSee('name="read_only_confirmed"', false)
        ->assertSee('Your saved keys')
        ->assertDontSee($values['apiKey'])->assertDontSee('FIRST')->assertDontSee('SECOND')
        ->assertDontSee('name="is_shared"', false);
});

it('requires the warning acknowledgement and never flashes submitted credentials on validation errors', function () {
    $exchange = settingsCredentialExchange();

    $this->actingAs(User::factory()->create())->from(route('settings.exchange-keys.index'))
        ->put(route('settings.exchange-keys.update', $exchange), [
            'credentials' => ['apiKey' => 'do-not-flash-me', 'secret' => 'do-not-flash-secret'],
        ])->assertSessionHasErrors(['read_only_confirmed' => 'Confirm that these credentials are read-only and restricted to public market information wherever supported.'])
        ->assertSessionMissing('_old_input.credentials');

    $this->assertDatabaseCount('exchange_credentials', 0);
});

it('rejects incomplete credential replacements without changing the saved keys', function () {
    $exchange = settingsCredentialExchange();
    $credential = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id]);
    $before = $credential->getRawOriginal('credentials');

    $this->actingAs($credential->user)->put(route('settings.exchange-keys.update', $exchange), [
        'credentials' => ['apiKey' => 'replacement-without-secret'], 'read_only_confirmed' => '1',
    ])->assertSessionHasErrors('credentials.secret')->assertSessionMissing('_old_input.credentials');

    expect($credential->fresh()->getRawOriginal('credentials'))->toBe($before);
});

it('rejects injected CCXT configuration and wallet private keys', function () {
    $exchange = settingsCredentialExchange();

    $this->actingAs(User::factory()->create())->put(route('settings.exchange-keys.update', $exchange), [
        'credentials' => ['apiKey' => 'test', 'secret' => 'test', 'privateKey' => 'wallet-secret', 'urls' => ['api' => 'https://invalid.test']],
        'read_only_confirmed' => '1',
    ])->assertSessionHasErrors('credentials')->assertSessionMissing('_old_input.credentials');

    $this->assertDatabaseCount('exchange_credentials', 0);
});

it('forbids non-owner sharing and does not create credentials', function () {
    $exchange = settingsCredentialExchange();

    $this->actingAs(User::factory()->create())->put(route('settings.exchange-keys.update', $exchange), [
        'credentials' => ['apiKey' => 'test', 'secret' => 'test'], 'is_shared' => '1', 'read_only_confirmed' => '1',
    ])->assertForbidden();

    $this->assertDatabaseCount('exchange_credentials', 0);
});

it('lets owners enable and stop sharing without re-entering or decrypting their keys', function () {
    $exchange = settingsCredentialExchange();
    $credential = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id]);
    config(['operations.owner_uuids' => [$credential->user_id]]);
    $before = $credential->getRawOriginal('credentials');

    $this->actingAs($credential->user)->put(route('settings.exchange-keys.update', $exchange), [
        'credentials' => ['apiKey' => '', 'secret' => ''], 'is_shared' => '1', 'read_only_confirmed' => '1',
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect($credential->fresh()->is_shared)->toBeTrue();
    expect($credential->fresh()->getRawOriginal('credentials'))->toBe($before);
    $this->get(route('settings.exchange-keys.index', ['exchange' => $exchange->exchange_id]))
        ->assertSee('name="is_shared"', false);

    $this->put(route('settings.exchange-keys.update', $exchange), ['read_only_confirmed' => '1'])
        ->assertSessionHasNoErrors()->assertRedirect();
    expect($credential->fresh()->is_shared)->toBeFalse();
});

it('shows shared access without exposing or allowing changes to another users credentials', function () {
    $exchange = settingsCredentialExchange();
    $owner = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id, 'is_shared' => true]);
    config(['operations.owner_uuid' => $owner->user_id]);
    $member = User::factory()->create();
    $before = $owner->getRawOriginal('credentials');

    $this->actingAs($member)->get(route('settings.exchange-keys.index', ['exchange' => $exchange->exchange_id]))
        ->assertSee('A server owner is sharing credentials')
        ->assertDontSee($owner->credentials['apiKey'])->assertDontSee($owner->credentials['secret']);
    $this->delete(route('settings.exchange-keys.destroy', $exchange))->assertNotFound();
    $this->put(route('settings.exchange-keys.update', $exchange), [
        'credentials' => ['apiKey' => 'personal', 'secret' => 'personal-secret'],
        'user_id' => $owner->user_id, 'read_only_confirmed' => '1',
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect($owner->fresh()->getRawOriginal('credentials'))->toBe($before);
    expect($member->exchangeCredentials()->sole()->credentials['apiKey'])->toBe('personal');
    $this->delete(route('settings.exchange-keys.destroy', $exchange))->assertRedirect();
    $this->assertModelExists($owner);
    expect($member->exchangeCredentials()->count())->toBe(0);
});

it('replaces a corrupt encrypted value without trying to decrypt the old credentials', function () {
    $exchange = settingsCredentialExchange();
    $credential = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id]);
    DB::table('exchange_credentials')->where('exchange_credential_id', $credential->getKey())->update(['credentials' => 'invalid-ciphertext']);

    $this->actingAs($credential->user)->put(route('settings.exchange-keys.update', $exchange), [
        'credentials' => ['apiKey' => 'replacement', 'secret' => 'replacement-secret'], 'read_only_confirmed' => '1',
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect($credential->fresh()->credentials['apiKey'])->toBe('replacement');
});

it('preserves significant whitespace in API secrets and passphrases', function () {
    $user = User::factory()->create();
    $exchange = Exchange::query()->create(['class' => 'coinbaseexchange', 'name' => 'Coinbase Exchange', 'config' => '{}']);
    $values = ['apiKey' => 'test-api-key', 'secret' => ' secret-with-spaces ', 'password' => ' passphrase-with-spaces '];

    $this->actingAs($user)->put(route('settings.exchange-keys.update', $exchange), [
        'credentials' => $values, 'read_only_confirmed' => '1',
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect($user->exchangeCredentials()->sole()->credentials)->toBe($values);
});

it('rejects suspended users before changing exchange credentials', function () {
    $exchange = settingsCredentialExchange();
    $credential = ExchangeCredential::factory()->create(['exchange_id' => $exchange->exchange_id]);
    $user = $credential->user;
    $user->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($user)->delete(route('settings.exchange-keys.destroy', $exchange))->assertForbidden();

    $this->assertModelExists($credential);
});

it('makes blocked feeds due after saving keys without replacing a live collection lease or affecting unrelated markets', function () {
    $this->travelTo('2026-10-01 12:00:00 UTC');
    $exchange = settingsCredentialExchange();
    $user = User::factory()->create();
    $other = User::factory()->create();
    $feeds = [];
    foreach (['BTC/USD', 'ETH/USD', 'LTC/USD'] as $i => $symbol) {
        $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => $symbol, 'tick_size' => '0.01']);
        MarketSubscription::query()->create(['user_id' => $i === 2 ? $other->user_id : $user->user_id, 'market_id' => $market->market_id, 'active' => true]);
        $feeds[] = MarketFeed::query()->create(['market_id' => $market->market_id, 'status' => 'blocked', 'next_pull_at' => now()->addHours(6), 'lease_token' => $i === 1 ? 'active-lease' : null]);
    }

    $this->actingAs($user)->put(route('settings.exchange-keys.update', $exchange), [
        'credentials' => ['apiKey' => 'test', 'secret' => 'test-secret'], 'read_only_confirmed' => '1',
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect($feeds[0]->fresh()->status)->toBe('pending');
    expect($feeds[0]->fresh()->next_pull_at->equalTo(now()))->toBeTrue();
    expect($feeds[1]->fresh()->lease_token)->toBe('active-lease');
    expect($feeds[1]->fresh()->status)->toBe('blocked');
    expect($feeds[2]->fresh()->status)->toBe('blocked');
});
