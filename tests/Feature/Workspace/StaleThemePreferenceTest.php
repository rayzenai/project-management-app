<?php

use App\Models\User;

it('falls back to the system theme when a saved theme no longer exists', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['theme' => 'terminal-noir']);

    $this->actingAs($user)->get('/workspace')
        ->assertInertia(fn ($page) => $page
            ->where('appearance.theme', 'system')
            ->where('appearance.mode', null)
            ->has('appearance.tokens.light.color')
            ->has('appearance.tokens.dark.color'));

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/user/preferences')
        ->assertSuccessful()
        ->assertJsonPath('data.theme', 'system');
});
