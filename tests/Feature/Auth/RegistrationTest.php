<?php

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    Validator::fakeDnsLookups();
    Notification::fake();

    $this->withoutExceptionHandling();

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'Trademinator#2026A',
        'password_confirmation' => 'Trademinator#2026A',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});
