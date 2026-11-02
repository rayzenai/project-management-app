<?php

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Mail\SetPasswordLink;
use App\Models\Member;
use App\Models\User;
use App\Services\Workspace\PasswordLinkService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;

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

it('answers the sixth request from one address in 15 minutes with 429, known email or not', function () {
    passwordApiPerson();

    foreach (range(1, 5) as $i) {
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
