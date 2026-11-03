<?php

use App\Mail\SetPasswordLink;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Sleep;

/*
 * One address in any case: emails are stored trimmed and lowercase, and
 * sign-in and password links look them up the same way. Logins made
 * before that are lowercased once by a migration, except two that differ
 * only in case, which are left for a person to sort out.
 */

beforeEach(function () {
    Mail::fake();
    Sleep::fake();
});

/** Runs the migration that lowercases the stored emails. */
function emailCaseMigrate(): void
{
    (require database_path('migrations/2026_11_03_140000_lowercase_emails.php'))->up();
}

/** A login stored as it was typed, as before the migration. */
function emailCaseLogin(string $email): int
{
    $id = DB::table('users')->insertGetId([
        'name' => 'Ram Sharma',
        'email' => $email,
        'password' => Hash::make('secret-pass'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('members')->insert([
        'user_id' => $id,
        'name' => 'Ram Sharma',
        'email' => $email,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('stores a login\'s and a person\'s email trimmed and in lowercase', function () {
    $user = User::factory()->create(['email' => '  Ram.Sharma@Example.TEST ']);
    $member = Member::factory()->create(['email' => 'Sita@Example.test']);
    $none = Member::factory()->create(['email' => null]);

    expect($user->fresh()->email)->toBe('ram.sharma@example.test')
        ->and($member->fresh()->email)->toBe('sita@example.test')
        ->and($none->fresh()->email)->toBeNull();
});

it('lowercases the emails stored before, so a mixed-case login signs in with any case', function () {
    $id = emailCaseLogin('Ram.Sharma@Example.test');

    emailCaseMigrate();

    expect(DB::table('users')->where('id', $id)->value('email'))->toBe('ram.sharma@example.test')
        ->and(DB::table('members')->where('user_id', $id)->value('email'))->toBe('ram.sharma@example.test');

    $this->post('/workspace/login', ['email' => 'RAM.sharma@example.TEST', 'password' => 'secret-pass'])
        ->assertRedirect('/workspace/my');
    $this->assertAuthenticatedAs(User::query()->findOrFail($id));

    $this->postJson('/api/v1/login', ['email' => 'Ram.Sharma@EXAMPLE.test', 'password' => 'secret-pass', 'device_name' => 'phone'])
        ->assertOk()
        ->assertJsonPath('user.email', 'ram.sharma@example.test');
});

it('finds the login in any case for a forgotten password, and sets it from the link in any case', function () {
    $id = emailCaseLogin('Ram.Sharma@Example.test');
    emailCaseMigrate();

    $this->post('/workspace/forgot-password', ['email' => 'RAM.SHARMA@example.test'])->assertSessionHasNoErrors();
    Mail::assertSent(SetPasswordLink::class, fn (SetPasswordLink $mail) => $mail->hasTo('ram.sharma@example.test'));

    $this->postJson('/api/v1/forgot-password', ['email' => 'Ram.Sharma@Example.TEST'])->assertOk();
    Mail::assertSent(SetPasswordLink::class, 2);

    $user = User::query()->findOrFail($id);
    $set = fn (string $email, string $token) => [
        'kind' => 'reset',
        'email' => $email,
        'token' => $token,
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ];

    $this->postJson('/api/v1/set-password', $set('Ram.Sharma@Example.test', Password::broker('users')->createToken($user)))->assertOk();
    expect(Hash::check('new-password-1', $user->fresh()->password))->toBeTrue();

    $this->post('/workspace/set-password', [...$set('RAM.SHARMA@EXAMPLE.TEST', Password::broker('users')->createToken($user)), 'password' => 'newer-password-2', 'password_confirmation' => 'newer-password-2'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('workspace.my'));
    expect(Hash::check('newer-password-2', $user->fresh()->password))->toBeTrue();
});

it('leaves two logins that differ only in case as they are, and says which', function () {
    Log::spy();
    $upper = emailCaseLogin('Ram@Example.test');
    $lower = emailCaseLogin('ram@example.test');
    $other = emailCaseLogin('Sita@Example.test');

    emailCaseMigrate();

    expect(DB::table('users')->whereIn('id', [$upper, $lower])->orderBy('id')->pluck('email')->all())
        ->toBe(['Ram@Example.test', 'ram@example.test'])
        ->and(DB::table('members')->whereIn('user_id', [$upper, $lower])->orderBy('user_id')->pluck('email')->all())
        ->toBe(['Ram@Example.test', 'ram@example.test'])
        ->and(DB::table('users')->where('id', $other)->value('email'))->toBe('sita@example.test');

    $memberIds = DB::table('members')->whereIn('user_id', [$upper, $lower])->orderBy('id')->pluck('id')->implode(', ');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'users')
        && str_contains($message, "ids {$upper}, {$lower}."))->once();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'members')
        && str_contains($message, "ids {$memberIds}."))->once();
    Log::shouldHaveReceived('warning')->twice();
});
