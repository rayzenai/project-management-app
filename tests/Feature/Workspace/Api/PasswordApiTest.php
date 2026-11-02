<?php

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\SetPasswordRequest;
use App\Mail\SetPasswordLink;
use App\Models\Member;
use App\Models\User;
use App\Services\Workspace\PasswordLinkService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Mail::fake();
    Sleep::fake();
});

function passwordApiPerson(string $email = 'sita@example.com', bool $active = true): User
{
    $user = User::factory()->create(['email' => $email, 'password' => Hash::make('old-password')]);
    $member = Member::factory()->linkedTo($user);
    ($active ? $member : $member->inactive())->create();

    return $user;
}

function passwordApiToken(string $email): string
{
    $sent = null;

    Mail::assertSent(SetPasswordLink::class, function (SetPasswordLink $mail) use ($email, &$sent): bool {
        $sent = $mail;

        return $mail->hasTo($email);
    });

    return basename((string) parse_url($sent->url, PHP_URL_PATH));
}

it('answers a known, an unknown and a deactivated email the same, mailing only the known one', function () {
    passwordApiPerson();
    passwordApiPerson('gone@example.com', active: false);

    foreach (['sita@example.com', 'nobody@example.com', 'gone@example.com'] as $email) {
        $this->postJson('/api/v1/forgot-password', ['email' => $email])
            ->assertOk()
            ->assertExactJson(['message' => PasswordLinkService::RESET_ANSWER]);
    }

    Mail::assertSent(SetPasswordLink::class, 1);
    Mail::assertSent(SetPasswordLink::class, fn (SetPasswordLink $mail): bool => $mail->hasTo('sita@example.com') && $mail->kind === 'reset');
});

it('sets the password from a mailed link and answers with a message only', function () {
    $user = passwordApiPerson();
    $user->createToken('phone');
    $this->postJson('/api/v1/forgot-password', ['email' => 'sita@example.com']);

    $this->postJson('/api/v1/set-password', [
        'kind' => 'reset',
        'email' => 'sita@example.com',
        'token' => passwordApiToken('sita@example.com'),
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ])
        ->assertOk()
        ->assertExactJson(['message' => 'Your password is set.']);

    expect(Hash::check('new-password-1', $user->fresh()->password))->toBeTrue()
        // The app may be signed in on a phone: its tokens stay.
        ->and($user->tokens()->count())->toBe(1);

    $this->postJson('/api/v1/login', ['email' => 'sita@example.com', 'password' => 'new-password-1', 'device_name' => 'pest'])
        ->assertOk();
});

it('refuses a used, wrong-kind or deactivated person\'s link with one answer', function () {
    $user = passwordApiPerson();
    $this->postJson('/api/v1/forgot-password', ['email' => 'sita@example.com']);
    $token = passwordApiToken('sita@example.com');
    $payload = ['kind' => 'reset', 'email' => 'sita@example.com', 'token' => $token, 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'];
    $refused = ['message' => PasswordLinkService::LINK_REFUSED, 'errors' => ['token' => PasswordLinkService::LINK_REFUSED]];

    $this->postJson('/api/v1/set-password', [...$payload, 'kind' => 'invite'])->assertStatus(422)->assertExactJson($refused);

    Member::query()->where('user_id', $user->id)->update(['is_active' => false]);
    $this->postJson('/api/v1/set-password', $payload)->assertStatus(422)->assertExactJson($refused);

    Member::query()->where('user_id', $user->id)->update(['is_active' => true]);
    $this->postJson('/api/v1/set-password', $payload)->assertOk();
    $this->postJson('/api/v1/set-password', [...$payload, 'password' => 'another-one-2', 'password_confirmation' => 'another-one-2'])
        ->assertStatus(422)->assertExactJson($refused);

    expect(Hash::check('new-password-1', $user->fresh()->password))->toBeTrue();
});

it('refuses an invite link after 8 days', function () {
    $user = passwordApiPerson();
    app(PasswordLinkService::class)->sendInvite($user, User::factory()->create());
    $token = passwordApiToken('sita@example.com');

    $this->travel(8)->days();

    $this->postJson('/api/v1/set-password', ['kind' => 'invite', 'email' => 'sita@example.com', 'token' => $token, 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
        ->assertStatus(422)
        ->assertJsonPath('errors.token', PasswordLinkService::LINK_REFUSED);
});

it('validates what it is sent', function () {
    $this->postJson('/api/v1/forgot-password', ['email' => 'not-an-email'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');

    $this->postJson('/api/v1/set-password', ['kind' => 'other', 'password' => 'short', 'password_confirmation' => 'different'])
        ->assertUnprocessable()->assertJsonValidationErrors(['kind', 'email', 'token', 'password']);
});

it('answers the thirty-first request from one address in 15 minutes with 429, known email or not', function () {
    passwordApiPerson();

    foreach (range(1, 30) as $i) {
        $this->postJson('/api/v1/forgot-password', ['email' => "nobody{$i}@example.com"])->assertOk();
    }

    $known = $this->postJson('/api/v1/forgot-password', ['email' => 'sita@example.com'])->assertStatus(429);
    $unknown = $this->postJson('/api/v1/forgot-password', ['email' => 'nobody9@example.com'])->assertStatus(429);

    expect($known->json())->toBe($unknown->json())
        ->and($known->json('errors.email.0'))->toBe(ForgotPasswordRequest::THROTTLED);
    Mail::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('counts the web and the API together', function () {
    passwordApiPerson();

    foreach (range(1, 5) as $i) {
        $this->from('/workspace/forgot-password')->post('/workspace/forgot-password', ['email' => 'sita@example.com']);
    }

    $this->postJson('/api/v1/forgot-password', ['email' => 'sita@example.com'])->assertStatus(429);
});

it('answers the eleventh set-password try in a minute from one address with 429', function () {
    $payload = ['kind' => 'reset', 'email' => 'nobody@example.com', 'token' => str_repeat('a', 64), 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'];

    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/set-password', $payload)->assertStatus(422);
    }

    $this->postJson('/api/v1/set-password', $payload)
        ->assertStatus(429)
        ->assertJsonPath('errors.password.0', SetPasswordRequest::THROTTLED);

    // Another address is not held back.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])->postJson('/api/v1/set-password', $payload)->assertStatus(422);
});

it('ends both pending links and remember-me sign-ins when an admin sets a password through the API', function () {
    $admin = User::factory()->create();
    Member::factory()->linkedTo($admin)->create(['is_office_admin' => true]);
    $person = passwordApiPerson();
    $member = Member::query()->where('user_id', $person->id)->firstOrFail();
    $remember = $person->remember_token;
    Password::broker('users')->createToken($person);
    Password::broker('invites')->createToken($person);
    Sanctum::actingAs($admin, ['*']);

    $this->patchJson("/api/v1/workspace/members/{$member->id}", ['password' => 'given-password-1'])->assertOk();

    expect($person->fresh()->remember_token)->not->toBe($remember)
        ->and(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(DB::table('invite_tokens')->count())->toBe(0);
});

it('counts only refused links toward the set-password limit', function () {
    $payload = ['kind' => 'reset', 'email' => 'nobody@example.com', 'token' => str_repeat('a', 64), 'password' => 'new-password-1'];

    foreach (range(1, 15) as $i) {
        $this->postJson('/api/v1/set-password', [...$payload, 'password_confirmation' => "typo-{$i}"])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    $this->postJson('/api/v1/set-password', [...$payload, 'password_confirmation' => 'new-password-1'])
        ->assertStatus(422)->assertJsonPath('errors.token', PasswordLinkService::LINK_REFUSED);
});
