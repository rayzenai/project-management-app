<?php

use App\Models\Member;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
});

it('exchanges valid credentials for a token and workspace context', function () {
    $user = User::factory()->create(['password' => bcrypt('secret-pass-1')]);

    $response = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'secret-pass-1',
        'device_name' => 'pest',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'token',
            'user' => ['id', 'name', 'email', 'member', 'is_super_admin', 'led_team_ids'],
        ])
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.is_super_admin', false)
        ->assertJsonPath('user.led_team_ids', []);

    expect($response->json('token'))->toBeString()->not->toBeEmpty();
});

it('reports a super-admin and their led teams in the login payload', function () {
    $user = User::factory()->create([
        'email' => 'boss@example.com',
        'password' => bcrypt('secret-pass-1'),
    ]);
    $member = Member::factory()->linkedTo($user)->create();
    $team = Team::factory()->create();
    $team->members()->attach($member->id, ['role' => 'leader']);

    $response = $this->postJson('/api/v1/login', [
        'email' => 'boss@example.com',
        'password' => 'secret-pass-1',
        'device_name' => 'pest',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.is_super_admin', true)
        ->assertJsonPath('user.led_team_ids', [$team->id])
        ->assertJsonPath('user.member.id', $member->id);
});

it('rejects a login with the wrong password', function () {
    $user = User::factory()->create(['password' => bcrypt('secret-pass-1')]);

    $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
        'device_name' => 'pest',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('rejects a login missing required fields', function () {
    $this->postJson('/api/v1/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password', 'device_name']);
});

it('forbids reaching a protected route without a token', function () {
    $this->getJson('/api/v1/user')->assertUnauthorized();
});

it('returns the workspace context for an authenticated user', function () {
    $user = User::factory()->create(['password' => bcrypt('secret-pass-1')]);

    $token = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'secret-pass-1',
        'device_name' => 'pest',
    ])->json('token');

    $this->withToken($token)->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('is_super_admin', false);
});

it('mints a token under the strict morph map (regression: User morph alias registered)', function () {
    // The package calls Relation::enforceMorphMap(), making the morph map strict
    // app-wide. Sanctum's personal_access_tokens.tokenable is a morph target, so
    // the provider MUST map the configured user model or login 500s with
    // ClassMorphViolationException. Assert login succeeds and the token persists
    // under the 'user' alias — guarding against that regression.
    $user = User::factory()->create(['password' => bcrypt('secret-pass-1')]);

    $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'secret-pass-1',
        'device_name' => 'pest',
    ])->assertOk();

    expect(PersonalAccessToken::query()->value('tokenable_type'))->toBe('user');
});

it('revokes the token on logout so it can no longer be used', function () {
    $user = User::factory()->create(['password' => bcrypt('secret-pass-1')]);

    $token = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'secret-pass-1',
        'device_name' => 'pest',
    ])->json('token');

    $this->withToken($token)->postJson('/api/v1/logout')->assertOk();

    expect(PersonalAccessToken::count())->toBe(0);

    // config/sanctum.php lists the `web` guard, so a logged-in request seeds a
    // session that the next in-process request would reuse via the session
    // guard — masking token revocation. Forget the resolved guards so the
    // follow-up request authenticates purely from the (now-revoked) token.
    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $this->withToken($token)->getJson('/api/v1/user')->assertUnauthorized();
});
