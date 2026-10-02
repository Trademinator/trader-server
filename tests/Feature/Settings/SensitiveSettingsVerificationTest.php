<?php

use App\Models\User;

it('requires verified email for API and exchange credentials but still allows password settings', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    $this->get('/settings/api-key')->assertRedirect('/verify-email');
    $this->post('/settings/api-key', ['label' => 'Desktop'])->assertRedirect('/verify-email');
    $this->get('/settings/exchange-keys')->assertRedirect('/verify-email');

    $this->get('/settings/password')->assertOk();
    $this->get('/settings/profile')->assertOk();
});
