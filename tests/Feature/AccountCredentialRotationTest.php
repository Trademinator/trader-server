<?php

use App\Models\ClientApiKey;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

function accountSecurityClientKey(User $user, string $marker): ClientApiKey
{
    $secret = 'tmk_'.str_repeat($marker, 43);

    return ClientApiKey::query()->create([
        'user_id' => $user->user_id,
        'label' => 'Desktop',
        'prefix' => substr($secret, 0, 12),
        'secret_hash' => hash('sha256', $secret),
    ]);
}

it('revokes persistent client credentials when the signed-in user changes password', function () {
    $user = User::factory()->create(['api_key' => 'legacy-password-key']);
    $rememberToken = $user->remember_token;
    $key = accountSecurityClientKey($user, 'p');

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->from('/settings/profile')
        ->put('/settings/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors()->assertRedirect('/settings/profile');

    $user->refresh();
    expect(Hash::check('new-password', $user->password))->toBeTrue()
        ->and($user->remember_token)->not->toBe($rememberToken)
        ->and($user->api_key)->toBeNull()
        ->and($key->refresh()->revoked_at)->not->toBeNull()
        ->and(session()->has('auth.password_confirmed_at'))->toBeFalse();
});

it('revokes persistent client credentials when a password reset succeeds', function () {
    Notification::fake();
    $user = User::factory()->create(['api_key' => 'legacy-reset-key']);
    $rememberToken = $user->remember_token;
    $key = accountSecurityClientKey($user, 'r');

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user, $key, $rememberToken) {
        $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $user->refresh();
        expect(Hash::check('new-password', $user->password))->toBeTrue()
            ->and($user->remember_token)->not->toBe($rememberToken)
            ->and($user->api_key)->toBeNull()
            ->and($key->refresh()->revoked_at)->not->toBeNull();

        return true;
    });
});
