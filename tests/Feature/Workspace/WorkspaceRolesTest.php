<?php

use App\Models\User;

it('treats a configured email as a manage-workspace user', function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $admin = User::factory()->create(['email' => 'boss@example.com']);
    $other = User::factory()->create(['email' => 'nobody@example.com']);

    expect($admin->can('manage-workspace'))->toBeTrue()
        ->and($other->can('manage-workspace'))->toBeFalse();
});

it('grants nobody manage-workspace when no super_admins are configured', function () {
    config(['project-management.super_admins' => []]);

    $user = User::factory()->create();

    expect($user->can('manage-workspace'))->toBeFalse();
});
