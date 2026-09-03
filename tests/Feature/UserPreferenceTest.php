<?php

use App\Models\User;

it('defaults appearance to system with no override', function () {
    $user = User::factory()->create();

    expect($user->appearance())->toMatchArray(['theme' => 'system', 'font_override' => null]);
});

it('persists a preference row and reads it back', function () {
    $user = User::factory()->create();
    $user->preferences()->create(['theme' => 'paper']);

    expect($user->fresh()->appearance()['theme'])->toBe('paper');
});

it('defaults email_notifications to true with no row', function () {
    $user = User::factory()->create();
    expect($user->appearance()['email_notifications'])->toBeTrue();
});
