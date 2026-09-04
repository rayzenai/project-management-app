<?php

use App\Models\User;

it('shares the users appearance on workspace pages', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['theme' => 'light']);

    $this->actingAs($user)->get('/workspace')
        ->assertInertia(fn ($page) => $page
            ->where('appearance.theme', 'light')
            ->where('appearance.mode', 'light')
            ->where('appearance.configured', true)
            ->has('appearance.tokens.color')
            ->has('appearance.tokens.font'));
});

it('marks appearance unconfigured for a user without saved preferences', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/workspace')
        ->assertInertia(fn ($page) => $page
            ->where('appearance.configured', false));
});

it('shares the theme catalogue so the appearance UI renders without an API fetch', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/workspace')
        ->assertInertia(fn ($page) => $page
            ->has('themeCatalogue.themes', 3)
            ->has('themeCatalogue.themes.dark')
            ->has('themeCatalogue.themes.system')
            ->has('themeCatalogue.fontAllowList.display')
            ->has('themeCatalogue.fontAllowList.body')
            ->has('themeCatalogue.fontAllowList.mono'));
});

it('resolves system appearance to light and dark token sets', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['theme' => 'system']);

    $this->actingAs($user)->get('/workspace')
        ->assertInertia(fn ($page) => $page
            ->where('appearance.theme', 'system')
            ->where('appearance.mode', null)
            ->has('appearance.tokens.light.color')
            ->has('appearance.tokens.dark.color'));
});
