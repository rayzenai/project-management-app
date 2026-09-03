<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia;

it('shows the branded login page to guests', function () {
    $this->get('/workspace/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Login'));
});

it('logs in with valid credentials and redirects into the workspace', function () {
    $user = User::factory()->create(['password' => Hash::make('secret-pass')]);

    $response = $this->post('/workspace/login', [
        'email' => $user->email,
        'password' => 'secret-pass',
    ]);

    $response->assertRedirect('/workspace');
    $this->assertAuthenticatedAs($user);
});

it('rejects invalid credentials and keeps the guest out', function () {
    $user = User::factory()->create(['password' => Hash::make('secret-pass')]);

    $response = $this->from('/workspace/login')->post('/workspace/login', [
        'email' => $user->email,
        'password' => 'wrong-pass',
    ]);

    $response->assertRedirect('/workspace/login');
    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('throttles after five failed attempts', function () {
    $user = User::factory()->create(['password' => Hash::make('secret-pass')]);

    foreach (range(1, 5) as $attempt) {
        $this->from('/workspace/login')->post('/workspace/login', [
            'email' => $user->email,
            'password' => 'wrong-pass',
        ]);
    }

    $response = $this->from('/workspace/login')->post('/workspace/login', [
        'email' => $user->email,
        'password' => 'secret-pass',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
})->afterEach(fn () => RateLimiter::clear(''));

it('redirects guests from a protected workspace route to the login page', function () {
    $this->get('/workspace')
        ->assertRedirect('/workspace/login');
});

it('redirects authenticated users away from the login page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/workspace/login')
        ->assertRedirect();
});
