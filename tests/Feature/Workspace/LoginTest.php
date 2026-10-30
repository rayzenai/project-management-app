<?php

use App\Http\Middleware\EnsureCanSignIn;
use App\Models\Member;
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

it('refuses a deactivated person, even with the right password', function () {
    $user = User::factory()->create(['password' => Hash::make('secret-pass')]);
    Member::factory()->linkedTo($user)->inactive()->create();

    $this->from('/workspace/login')->post('/workspace/login', [
        'email' => $user->email,
        'password' => 'secret-pass',
    ])
        ->assertRedirect('/workspace/login')
        ->assertSessionHasErrors(['email' => EnsureCanSignIn::DEACTIVATED]);

    $this->assertGuest();
});

it('still says the credentials are wrong to a deactivated person with the wrong password', function () {
    $user = User::factory()->create(['password' => Hash::make('secret-pass')]);
    Member::factory()->linkedTo($user)->inactive()->create();

    $this->from('/workspace/login')->post('/workspace/login', [
        'email' => $user->email,
        'password' => 'wrong-pass',
    ])->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
})->afterEach(fn () => RateLimiter::clear(''));

it('lets a super-admin sign in even when their own member is deactivated', function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    $user = User::factory()->create(['email' => 'boss@example.com', 'password' => Hash::make('secret-pass')]);
    Member::factory()->linkedTo($user)->inactive()->create();

    $this->post('/workspace/login', [
        'email' => 'boss@example.com',
        'password' => 'secret-pass',
    ])->assertRedirect('/workspace');

    $this->assertAuthenticatedAs($user);
});

it('signs out someone deactivated while signed in', function () {
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->inactive()->create();

    $this->actingAs($user)
        ->get('/workspace/my')
        ->assertRedirect('/workspace/login');

    $this->assertGuest();
});

it('sends a deactivated person\'s Inertia visit to the login page as a full page load', function () {
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->inactive()->create();

    $this->actingAs($user)
        ->withHeader('X-Inertia', 'true')
        ->post('/workspace/notifications/read-all')
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', url('/workspace/login'));

    $this->assertGuest();
});

it('signs a deactivated person out on the way to the forge', function () {
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->inactive()->create();

    $this->actingAs($user)
        ->get('/oauth/authorize')
        ->assertRedirect('/workspace/login');

    $this->assertGuest();
});
