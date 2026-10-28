<?php

use App\Mail\LoginDetails;
use App\Models\Member;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    Mail::fake();
});

function loginDetailsBoss(): User
{
    return User::factory()->create(['email' => 'boss@example.com', 'name' => 'The Boss']);
}

function sentLoginDetails(string $email): LoginDetails
{
    $sent = null;

    Mail::assertSent(LoginDetails::class, function (LoginDetails $mail) use ($email, &$sent): bool {
        $sent = $mail;

        return $mail->hasTo($email);
    });

    return $sent;
}

it('emails a password that signs in', function () {
    $this->actingAs(loginDetailsBoss())
        ->post('/workspace/members', ['name' => 'Sita Sharma', 'email' => 'sita@example.com', 'password' => 'Kp7m-Qx4r-Tz9a']);

    $mail = sentLoginDetails('sita@example.com');

    expect($mail->sentBy)->toBe('The Boss')
        ->and(auth()->validate(['email' => 'sita@example.com', 'password' => $mail->password]))->toBeTrue();
});

it('shows the password exactly, escaped in HTML and as typed in text', function () {
    $mail = new LoginDetails(name: 'Sita', email: 'sita@example.com', password: '*a_b`<i>&c', sentBy: 'The Boss');

    $mail->assertSeeInHtml('*a_b`&lt;i&gt;&amp;c', escape: false)
        ->assertDontSeeInHtml('<i>', escape: false)
        ->assertSeeInText('Password: *a_b`<i>&c')
        ->assertSeeInHtml(route('workspace.login'));
});

it('still adds the member when the email cannot be sent', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

    $this->actingAs(loginDetailsBoss())
        ->post('/workspace/members', ['name' => 'Sita Sharma', 'email' => 'sita@example.com', 'password' => 'Kp7m-Qx4r-Tz9a'])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash.success', false);

    expect(auth()->validate(['email' => 'sita@example.com', 'password' => 'Kp7m-Qx4r-Tz9a']))->toBeTrue();
});

it('sends nothing when a leader is refused', function () {
    $own = Team::factory()->create();
    $foreign = Team::factory()->create();
    $leader = User::factory()->create();
    $own->members()->attach(Member::factory()->linkedTo($leader)->create()->id, ['role' => 'leader']);

    $this->actingAs($leader)
        ->post('/workspace/members', ['name' => 'Wrong Team', 'email' => 'wrong@example.com', 'password' => 'Kp7m-Qx4r-Tz9a', 'team_ids' => [$foreign->id]])
        ->assertForbidden();

    Mail::assertNothingSent();
    expect(User::query()->where('email', 'wrong@example.com')->exists())->toBeFalse();
});

it('generates and emails a password when an API caller sends none', function () {
    Sanctum::actingAs(loginDetailsBoss(), ['*']);

    $this->postJson('/api/v1/workspace/members', ['name' => 'Sita Sharma', 'email' => 'sita@example.com'])
        ->assertCreated()
        ->assertJsonPath('message', 'Member added. Login details emailed.');

    $mail = sentLoginDetails('sita@example.com');

    expect(strlen($mail->password))->toBe(14)
        ->and(auth('web')->validate(['email' => 'sita@example.com', 'password' => $mail->password]))->toBeTrue();
});
