<?php

use App\Models\User;

it('defaults appearance to system with no override', function () {
    $user = User::factory()->create();

    expect($user->appearance())->toMatchArray(['theme' => 'system', 'font_override' => null]);
});

it('persists a preference row and reads it back', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['theme' => 'dark']);

    expect($user->fresh()->appearance()['theme'])->toBe('dark');
});

it('defaults every email setting to on with no row', function () {
    $user = User::factory()->create();
    expect($user->emailPreferences())->toBe(['now' => true, 'morning' => true, 'weekly' => true]);
});
