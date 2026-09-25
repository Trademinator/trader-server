<?php

use App\Models\User;
use Illuminate\Support\Str;

it('updates api_key using consistently named request fields', function () {
    $current = (string) Str::uuid7();
    $replacement = (string) Str::uuid7();
    $user = User::factory()->create(['api_key' => $current]);

    $response = $this
        ->actingAs($user)
        ->from('/settings/api-key')
        ->put('/settings/api-key', [
            'current_api_key' => $current,
            'api_key' => $replacement,
            'api_key_confirmation' => $replacement,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/api-key');

    expect($user->refresh()->api_key)->toBe($replacement);
});

it('rejects api key rotation when the current key is wrong', function () {
    $user = User::factory()->create(['api_key' => (string) Str::uuid7()]);
    $replacement = (string) Str::uuid7();

    $response = $this
        ->actingAs($user)
        ->from('/settings/api-key')
        ->put('/settings/api-key', [
            'current_api_key' => (string) Str::uuid7(),
            'api_key' => $replacement,
            'api_key_confirmation' => $replacement,
        ]);

    $response
        ->assertSessionHasErrors('current_api_key')
        ->assertRedirect('/settings/api-key');
});

it('allows a legacy user with no api key to set the first one', function () {
    $user = User::factory()->create(['api_key' => null]);
    $replacement = (string) Str::uuid7();

    $response = $this
        ->actingAs($user)
        ->from('/settings/api-key')
        ->put('/settings/api-key', [
            'current_api_key' => null,
            'api_key' => $replacement,
            'api_key_confirmation' => $replacement,
        ]);

    $response->assertSessionHasNoErrors();
    expect($user->refresh()->api_key)->toBe($replacement);
});
