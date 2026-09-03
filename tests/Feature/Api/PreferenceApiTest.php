<?php

use App\Models\User;

it('returns themes with full token sets', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/themes')
        ->assertSuccessful()
        ->assertJsonPath('data.themes.terminal-noir.tokens.color.accent', '#b6ff3a');
});

it('gets and updates preferences with validation', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/user/preferences')
        ->assertSuccessful()->assertJsonPath('data.theme', 'system');

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/user/preferences', ['theme' => 'paper'])
        ->assertSuccessful()->assertJsonPath('data.theme', 'paper');

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/user/preferences', ['theme' => 'nope'])
        ->assertStatus(422);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/user/preferences', ['font_override' => ['body' => 'NotAllowed']])
        ->assertStatus(422);
});

it('updates email_notifications', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/user/preferences')
        ->assertJsonPath('data.email_notifications', true);
    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/user/preferences', ['email_notifications' => false])
        ->assertSuccessful()->assertJsonPath('data.email_notifications', false);
    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/user/preferences', ['email_notifications' => 'notbool'])
        ->assertStatus(422);
});

it('reports configured false for a fresh user and true after a patch', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/user/preferences')
        ->assertSuccessful()->assertJsonPath('data.configured', false);

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/user/preferences', ['theme' => 'dark'])
        ->assertSuccessful()->assertJsonPath('data.configured', true);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/user/preferences')
        ->assertJsonPath('data.configured', true);
});

it('resolves system theme to both light and dark token sets', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/user/preferences')
        ->assertJsonPath('data.theme', 'system')
        ->assertJsonPath('data.resolved_tokens.light.color.bg', '#f7f7f5')
        ->assertJsonPath('data.resolved_tokens.dark.color.bg', '#0e0f12');
});
