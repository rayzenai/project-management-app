<?php

use App\Models\User;

it('logs the user out and returns an Inertia full-page location visit', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeader('X-Inertia', 'true')
        ->post('/workspace/logout');

    $response->assertStatus(409);
    $response->assertHeader('X-Inertia-Location', url('/workspace/login'));

    $this->assertGuest();
});

it('redirects guests to login instead of looping when logout is hit unauthenticated', function () {
    $response = $this->withHeader('X-Inertia', 'true')
        ->post('/workspace/logout');

    $this->assertGuest();
    expect($response->status())->not->toBe(200);
});
