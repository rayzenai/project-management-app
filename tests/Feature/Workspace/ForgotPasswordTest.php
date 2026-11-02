<?php

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Mail\SetPasswordLink;
use App\Models\Member;
use App\Models\User;
use App\Services\Workspace\PasswordLinkService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(function () {
    Mail::fake();
    // A refused link is held to a fixed answer time; the tests don't wait it out.
    Sleep::fake();
});

function forgotPasswordPerson(string $email = 'sita@example.com', bool $active = true): User
{
    $user = User::factory()->create(['email' => $email, 'password' => Hash::make('old-password')]);
    $member = Member::factory()->linkedTo($user);
    ($active ? $member : $member->inactive())->create();

    return $user;
}

/** The one link mailed to an email. */
function forgotPasswordLinkTo(string $email): SetPasswordLink
{
    $sent = null;

    Mail::assertSent(SetPasswordLink::class, function (SetPasswordLink $mail) use ($email, &$sent): bool {
        $sent = $mail;

        return $mail->hasTo($email);
    });
    Mail::assertSent(SetPasswordLink::class, 1);

    return $sent;
}

/** The token in a mailed link: /workspace/{reset-password|invite}/{token}?email=... */
function forgotPasswordToken(SetPasswordLink $mail): string
{
    return basename((string) parse_url($mail->url, PHP_URL_PATH));
}

function forgotPasswordInviteToken(User $user): string
{
    $inviter = User::factory()->create(['name' => 'Ram Thapa']);
    app(PasswordLinkService::class)->sendInvite($user, $inviter);

    $sent = null;
    Mail::assertSent(SetPasswordLink::class, function (SetPasswordLink $mail) use ($user, &$sent): bool {
        $sent = $mail;

        return $mail->hasTo($user->email) && $mail->kind === 'invite';
    });

    return forgotPasswordToken($sent);
}

function forgotPasswordSet(string $kind, string $email, string $token, string $password = 'new-password-1'): TestResponse
{
    return test()->from('/workspace/login')->post('/workspace/set-password', [
        'kind' => $kind,
        'email' => $email,
        'token' => $token,
        'password' => $password,
        'password_confirmation' => $password,
    ]);
}

it('links the login page to forgotten password', function () {
    $login = (string) file_get_contents(resource_path('js/pages/Auth/Login.svelte'));

    expect($login)->toContain('href="/workspace/forgot-password"')
        ->and($login)->toContain('Forgot your password?');

    $this->get('/workspace/forgot-password')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/ForgotPassword')->where('status', null));
});

it('mails a known, active person one link that opens the set-password page', function () {
    $user = forgotPasswordPerson();

    $this->from('/workspace/forgot-password')
        ->post('/workspace/forgot-password', ['email' => 'sita@example.com'])
        ->assertRedirect('/workspace/forgot-password')
        ->assertSessionHas('status', PasswordLinkService::RESET_ANSWER);

    $mail = forgotPasswordLinkTo('sita@example.com');
    $token = forgotPasswordToken($mail);

    expect($mail->kind)->toBe('reset')
        ->and($mail->url)->toBe(route('workspace.password.reset', ['token' => $token, 'email' => 'sita@example.com']));

    $this->get($mail->url)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/SetPassword')
            ->where('kind', 'reset')
            ->where('token', $token)
            ->where('email', 'sita@example.com'));

    // Opening the link changes nothing (mail scanners open links).
    expect(Hash::check('old-password', $user->fresh()->password))->toBeTrue();
});

it('answers an unknown email and a deactivated person exactly as a known one, and mails nobody', function () {
    forgotPasswordPerson('gone@example.com', active: false);

    foreach (['nobody@example.com', 'gone@example.com'] as $email) {
        $this->from('/workspace/forgot-password')
            ->post('/workspace/forgot-password', ['email' => $email])
            ->assertRedirect('/workspace/forgot-password')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', PasswordLinkService::RESET_ANSWER);
    }

    Mail::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('looks the email up only after the answer is sent, so its timing tells nothing', function () {
    forgotPasswordPerson();

    $result = app(PasswordLinkService::class)->sendReset('sita@example.com');

    expect($result->success)->toBeTrue()
        ->and($result->message)->toBe(PasswordLinkService::RESET_ANSWER);
    Mail::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0);

    app(DeferredCallbackCollection::class)->invoke();

    forgotPasswordLinkTo('sita@example.com');
});

it('answers the same when the email cannot be sent, and reports it', function () {
    Exceptions::fake();
    breakMailTransport();
    forgotPasswordPerson();

    $this->from('/workspace/forgot-password')
        ->post('/workspace/forgot-password', ['email' => 'sita@example.com'])
        ->assertRedirect('/workspace/forgot-password')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', PasswordLinkService::RESET_ANSWER);

    Exceptions::assertReported(TransportException::class);
});

it('sets the password from the link, signs the person in and opens My work', function () {
    $user = forgotPasswordPerson();
    $oldRemember = $user->remember_token;
    $this->post('/workspace/forgot-password', ['email' => 'sita@example.com']);
    $token = forgotPasswordToken(forgotPasswordLinkTo('sita@example.com'));

    forgotPasswordSet('reset', 'sita@example.com', $token)
        ->assertRedirect(route('workspace.my'))
        ->assertSessionHas('workspace_flash.message', 'Your password is set.');

    $this->assertAuthenticatedAs($user);
    $user->refresh();
    expect(Hash::check('new-password-1', $user->password))->toBeTrue()
        ->and($user->remember_token)->not->toBe($oldRemember)
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('works once: the same link a second time is refused', function () {
    forgotPasswordPerson();
    $this->post('/workspace/forgot-password', ['email' => 'sita@example.com']);
    $token = forgotPasswordToken(forgotPasswordLinkTo('sita@example.com'));

    forgotPasswordSet('reset', 'sita@example.com', $token)->assertRedirect(route('workspace.my'));
    Auth::logout();

    forgotPasswordSet('reset', 'sita@example.com', $token, 'another-password-2')
        ->assertRedirect('/workspace/login')
        ->assertSessionHasErrors(['token' => PasswordLinkService::LINK_REFUSED]);

    $this->assertGuest();
    expect(Hash::check('new-password-1', User::query()->where('email', 'sita@example.com')->value('password')))->toBeTrue();
});

it('refuses a reset link older than 60 minutes', function () {
    forgotPasswordPerson();
    $this->post('/workspace/forgot-password', ['email' => 'sita@example.com']);
    $token = forgotPasswordToken(forgotPasswordLinkTo('sita@example.com'));

    $this->travel(61)->minutes();

    forgotPasswordSet('reset', 'sita@example.com', $token)
        ->assertSessionHasErrors(['token' => PasswordLinkService::LINK_REFUSED]);
    $this->assertGuest();
});

it('keeps the two kinds apart: an invite link is no reset link and the other way round', function () {
    $user = forgotPasswordPerson();
    $inviteToken = forgotPasswordInviteToken($user);
    $this->post('/workspace/forgot-password', ['email' => 'sita@example.com']);
    $resetToken = null;
    Mail::assertSent(SetPasswordLink::class, function (SetPasswordLink $mail) use (&$resetToken): bool {
        if ($mail->kind === 'reset') {
            $resetToken = forgotPasswordToken($mail);
        }

        return true;
    });

    forgotPasswordSet('reset', 'sita@example.com', $inviteToken)
        ->assertSessionHasErrors(['token' => PasswordLinkService::LINK_REFUSED]);
    forgotPasswordSet('invite', 'sita@example.com', $resetToken)
        ->assertSessionHasErrors(['token' => PasswordLinkService::LINK_REFUSED]);

    $this->assertGuest();
    expect(Hash::check('old-password', $user->fresh()->password))->toBeTrue();
});

it('lets an invite link work after 6 days', function () {
    $user = forgotPasswordPerson();
    $token = forgotPasswordInviteToken($user);

    $this->travel(6)->days();

    forgotPasswordSet('invite', 'sita@example.com', $token)->assertRedirect(route('workspace.my'));
    $this->assertAuthenticatedAs($user);
});

it('refuses an invite link after 8 days', function () {
    $user = forgotPasswordPerson();
    $token = forgotPasswordInviteToken($user);

    $this->travel(8)->days();

    forgotPasswordSet('invite', 'sita@example.com', $token)
        ->assertSessionHasErrors(['token' => PasswordLinkService::LINK_REFUSED]);
    $this->assertGuest();
});

it('refuses a deactivated person\'s valid link with the same words as a dead one', function () {
    $user = forgotPasswordPerson();
    $this->post('/workspace/forgot-password', ['email' => 'sita@example.com']);
    $token = forgotPasswordToken(forgotPasswordLinkTo('sita@example.com'));

    Member::query()->where('user_id', $user->id)->update(['is_active' => false]);

    forgotPasswordSet('reset', 'sita@example.com', $token)
        ->assertSessionHasErrors(['token' => PasswordLinkService::LINK_REFUSED]);

    $this->assertGuest();
    expect(Hash::check('old-password', $user->fresh()->password))->toBeTrue();
});

it('holds a refused link to a fixed answer time and lets a working one through at once', function () {
    forgotPasswordPerson();

    forgotPasswordSet('reset', 'nobody@example.com', str_repeat('a', 64))->assertSessionHasErrors('token');
    Sleep::assertSleptTimes(1);

    Sleep::fake();
    $this->post('/workspace/forgot-password', ['email' => 'sita@example.com']);
    $token = forgotPasswordToken(forgotPasswordLinkTo('sita@example.com'));
    forgotPasswordSet('reset', 'sita@example.com', $token)->assertRedirect(route('workspace.my'));
    Sleep::assertNeverSlept();
});

it('keeps the link when the new password is not accepted', function () {
    $user = forgotPasswordPerson();
    $this->post('/workspace/forgot-password', ['email' => 'sita@example.com']);
    $token = forgotPasswordToken(forgotPasswordLinkTo('sita@example.com'));

    $this->from('/workspace/login')->post('/workspace/set-password', [
        'kind' => 'reset',
        'email' => 'sita@example.com',
        'token' => $token,
        'password' => 'new-password-1',
        'password_confirmation' => 'something-else',
    ])->assertSessionHasErrors('password');

    forgotPasswordSet('reset', 'sita@example.com', $token)->assertRedirect(route('workspace.my'));
    $this->assertAuthenticatedAs($user);
});

it('signs out the person\'s other web sessions when their password is set', function () {
    $user = forgotPasswordPerson();

    // Another browser, signed in with the old password.
    $this->post('/workspace/login', ['email' => 'sita@example.com', 'password' => 'old-password'])->assertRedirect();
    $this->get('/workspace/my')->assertOk();

    $token = Password::broker('users')->createToken($user);
    expect(app(PasswordLinkService::class)->setPassword('reset', 'sita@example.com', $token, 'new-password-1')->success)->toBeTrue();

    // That browser's next request, with the user read afresh as production does.
    Auth::forgetGuards();
    $this->get('/workspace/my')->assertRedirect('/workspace/login');
    $this->assertGuest();
});

it('keeps the session of an admin who changes their own password on the person form', function () {
    $user = User::factory()->create(['email' => 'admin@example.com', 'password' => Hash::make('old-password')]);
    $member = Member::factory()->linkedTo($user)->create(['is_office_admin' => true]);

    $this->post('/workspace/login', ['email' => 'admin@example.com', 'password' => 'old-password'])->assertRedirect();
    $this->from('/workspace/team')->patch("/workspace/members/{$member->id}", ['password' => 'new-password-1'])
        ->assertSessionHasNoErrors();

    Auth::forgetGuards();
    $this->get('/workspace/my')->assertOk();
    $this->assertAuthenticatedAs($user);
});

it('sends the sixth request from one address in 15 minutes away, known email or not', function () {
    forgotPasswordPerson();

    foreach (range(1, 5) as $i) {
        $this->from('/workspace/forgot-password')
            ->post('/workspace/forgot-password', ['email' => "nobody{$i}@example.com"])
            ->assertSessionHasNoErrors();
    }

    $this->from('/workspace/forgot-password')
        ->post('/workspace/forgot-password', ['email' => 'sita@example.com'])
        ->assertSessionHasErrors(['email' => ForgotPasswordRequest::THROTTLED]);

    Mail::assertNothingSent();

    $this->travel(16)->minutes();

    $this->from('/workspace/forgot-password')
        ->post('/workspace/forgot-password', ['email' => 'sita@example.com'])
        ->assertSessionHasNoErrors();
    forgotPasswordLinkTo('sita@example.com');
});

it('limits one email to five requests in 15 minutes from any address', function () {
    forgotPasswordPerson();

    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
            ->from('/workspace/forgot-password')
            ->post('/workspace/forgot-password', ['email' => 'sita@example.com'])
            ->assertSessionHasNoErrors();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->from('/workspace/forgot-password')
        ->post('/workspace/forgot-password', ['email' => 'SITA@example.com'])
        ->assertSessionHasErrors(['email' => ForgotPasswordRequest::THROTTLED]);

    Mail::assertSent(SetPasswordLink::class, 5);
});

it('opens the invite link on the set-password page as an invite', function () {
    $user = forgotPasswordPerson();
    $token = forgotPasswordInviteToken($user);

    $this->get(route('workspace.invite.accept', ['token' => $token, 'email' => 'sita@example.com']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/SetPassword')
            ->where('kind', 'invite')
            ->where('token', $token)
            ->where('email', 'sita@example.com'));
});

it('marks an invite sent and mails its link', function () {
    Carbon::setTestNow('2026-11-03 10:00:00');
    $user = forgotPasswordPerson();
    $inviter = User::factory()->create(['name' => 'Ram Thapa']);

    $result = app(PasswordLinkService::class)->sendInvite($user, $inviter);

    expect($result->success)->toBeTrue()
        ->and($user->fresh()->invited_at?->toDateTimeString())->toBe('2026-11-03 10:00:00');

    $mail = forgotPasswordLinkTo('sita@example.com');
    expect($mail->kind)->toBe('invite')
        ->and($mail->inviterName)->toBe('Ram Thapa')
        ->and($mail->url)->toStartWith(url('/workspace/invite/'));
    expect(DB::table('invite_tokens')->where('email', 'sita@example.com')->exists())->toBeTrue()
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('never invites a deactivated person', function () {
    $user = forgotPasswordPerson(active: false);

    $result = app(PasswordLinkService::class)->sendInvite($user, User::factory()->create());

    expect($result->success)->toBeFalse()
        ->and($user->fresh()->invited_at)->toBeNull()
        ->and(DB::table('invite_tokens')->count())->toBe(0);
    Mail::assertNothingSent();
});

it('words the reset mail plainly, with one button and no email settings', function () {
    $user = User::factory()->make(['email' => 'sita@example.com']);
    $mail = new SetPasswordLink($user, 'https://tasks.example/workspace/reset-password/abc?email=sita%40example.com', 'reset', null);

    expect($mail->envelope()->subject)->toBe('Set a new password');
    $mail->assertSeeInHtml('Someone asked to reset the password for sita@example.com. The link works for 60 minutes. If it wasn&#039;t you, ignore this email.', escape: false)
        ->assertSeeInHtml('Set my password')
        ->assertSeeInHtml('https://tasks.example/workspace/reset-password/abc?email=sita%40example.com')
        ->assertDontSeeInHtml('Change your emails')
        ->assertSeeInText("Someone asked to reset the password for sita@example.com. The link works for 60 minutes. If it wasn't you, ignore this email.")
        ->assertSeeInText('Set my password: https://tasks.example/workspace/reset-password/abc?email=sita%40example.com');
});

it('words the invite mail with who invited and how long the link lasts', function () {
    config(['app.name' => 'SDOC Tasks']);
    $user = User::factory()->make(['email' => 'sita@example.com']);
    $mail = new SetPasswordLink($user, 'https://tasks.example/workspace/invite/abc?email=sita%40example.com', 'invite', 'Ram Thapa');

    expect($mail->envelope()->subject)->toBe('Ram Thapa invited you to SDOC Tasks');
    $mail->assertSeeInHtml('Set your password to start. The link works for 7 days.')
        ->assertSeeInHtml('Set my password')
        ->assertDontSeeInHtml('Change your emails')
        ->assertSeeInText('Set my password: https://tasks.example/workspace/invite/abc?email=sita%40example.com');
});
