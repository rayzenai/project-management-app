<?php

use App\Models\User;

it('shares configured false for a fresh user and true after saving via the web route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/workspace')
        ->assertInertia(fn ($page) => $page->where('appearance.configured', false));

    $this->actingAs($user)->patch('/workspace/preferences', [
        'theme' => 'light',
        'email_notifications' => false,
    ])->assertRedirect();

    $this->actingAs($user->fresh())->get('/workspace')
        ->assertInertia(fn ($page) => $page
            ->where('appearance.configured', true)
            ->where('appearance.theme', 'light')
            ->where('appearance.email_notifications', false));
});

it('validates the theme on the web preferences route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/workspace/preferences', ['theme' => 'nope'])
        ->assertSessionHasErrors('theme');
});

it('shares the current font_override on workspace pages', function () {
    $user = User::factory()->create();
    $user->preferences()->create([
        'theme' => 'light',
        'font_override' => ['display' => null, 'body' => 'Geist', 'mono' => null],
    ]);

    $this->actingAs($user)->get('/workspace')
        ->assertInertia(fn ($page) => $page->where('appearance.font_override.body', 'Geist'));
});
