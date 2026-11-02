<?php

use App\Models\User;

it('shows the three email settings, all on by default', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/workspace/settings/emails')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Emails')
            ->where('emails', ['now' => true, 'morning' => true, 'weekly' => true])
            ->where('morningAt', '09:00'));
});

it('shows what was saved', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['email_notifications' => false, 'email_morning' => true, 'email_weekly' => false]);

    $this->actingAs($user)->get(route('workspace.settings.emails'))
        ->assertInertia(fn ($page) => $page
            ->where('emails', ['now' => false, 'morning' => true, 'weekly' => false]));
});

it('saves each setting on its own', function (string $field, string $key) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/workspace/settings/emails')
        ->patch('/workspace/preferences', [$field => false])
        ->assertRedirect('/workspace/settings/emails');

    $emails = $user->fresh()->emailPreferences();
    expect($emails[$key])->toBeFalse()
        ->and(array_filter($emails))->toHaveCount(2);
})->with([
    'right away' => ['email_notifications', 'now'],
    'morning' => ['email_morning', 'morning'],
    'weekly' => ['email_weekly', 'weekly'],
]);

it('refuses a setting that is not on or off', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/workspace/preferences', ['email_morning' => 'sometimes'])
        ->assertSessionHasErrors('email_morning');
});

it('keeps the email settings out of the appearance', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/workspace/my')
        ->assertInertia(fn ($page) => $page->missing('appearance.email_notifications'));
});

it('asks a guest to sign in', function () {
    $this->get('/workspace/settings/emails')->assertRedirect(route('workspace.login'));
});
