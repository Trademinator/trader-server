<?php

use App\Models\ClientApiKey;
use App\Models\User;

it('creates a hashed client api key and shows the secret only in the redirect flash', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/settings/api-key', ['label' => 'Desktop Client']);

    $response->assertRedirect('/settings/api-key')->assertSessionHas('new_client_api_key');
    $secret = session('new_client_api_key');
    expect($secret)->toStartWith('tmk_');

    $key = ClientApiKey::query()->where('user_id', $user->user_id)->firstOrFail();
    expect($key->label)->toBe('Desktop Client')
        ->and($key->prefix)->toBe(substr($secret, 0, 20))
        ->and(strlen($key->prefix))->toBe(20)
        ->and($key->secret_hash)->toBe(hash('sha256', $secret))
        ->and($key->secret_hash)->not->toContain($secret);
});

it('revokes only the selected client api key', function () {
    $user = User::factory()->create();
    $one = ClientApiKey::query()->create(['user_id' => $user->user_id, 'label' => 'One', 'prefix' => 'tmk_12345678', 'secret_hash' => hash('sha256', 'one')]);
    $two = ClientApiKey::query()->create(['user_id' => $user->user_id, 'label' => 'Two', 'prefix' => 'tmk_abcdefgh', 'secret_hash' => hash('sha256', 'two')]);

    $this->actingAs($user)->delete('/settings/api-key/'.$one->getKey())->assertRedirect('/settings/api-key');

    expect($one->refresh()->revoked_at)->not->toBeNull()
        ->and($two->refresh()->revoked_at)->toBeNull();
});
