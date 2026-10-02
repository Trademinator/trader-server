<?php

use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;

it('requires recent password confirmation for privileged owner mutations', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $target = User::factory()->create();

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => 0]);

    $this->put('/owner/users/'.$target->user_id, ['operation' => 'suspend'])->assertRedirect('/confirm-password');
    $this->post('/owner/archives/restore', [])->assertRedirect('/confirm-password');
    $this->post('/owner/archives/export')->assertRedirect('/confirm-password');
    $this->post('/owner/archives/import', [])->assertRedirect('/confirm-password');
});
