<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

function issuedToken(string $output): string
{
    $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));

    return end($lines);
}

it('issues a token that authenticates against the workspace API', function () {
    $user = User::factory()->create(['email' => 'bot@example.com']);

    Artisan::call('workspace:api-token', ['email' => 'bot@example.com', '--name' => 'crm-sync']);

    $token = issuedToken(Artisan::output());

    expect($user->tokens()->where('name', 'crm-sync')->count())->toBe(1);

    $this->withToken($token)->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('email', 'bot@example.com');
});

it('honours an expiry in days', function () {
    User::factory()->create(['email' => 'bot@example.com']);

    $this->travelTo(now()->startOfDay());

    Artisan::call('workspace:api-token', ['email' => 'bot@example.com', '--expires-days' => 30]);

    $token = issuedToken(Artisan::output());

    $this->withToken($token)->getJson('/api/v1/user')->assertOk();

    $this->travel(31)->days();
    // The request guard caches the resolved user for the rest of the test.
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/user')->assertUnauthorized();
});

it('revokes every token with the given name', function () {
    $user = User::factory()->create(['email' => 'bot@example.com']);
    $user->createToken('integration');
    $user->createToken('integration');
    $user->createToken('phone');

    $this->artisan('workspace:api-token', ['email' => 'bot@example.com', '--revoke' => true])
        ->expectsOutputToContain('Revoked 2 token(s)')
        ->assertSuccessful();

    expect($user->tokens()->pluck('name')->all())->toBe(['phone']);
});

it('fails for an unknown user', function () {
    $this->artisan('workspace:api-token', ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('No user with email')
        ->assertFailed();
});
