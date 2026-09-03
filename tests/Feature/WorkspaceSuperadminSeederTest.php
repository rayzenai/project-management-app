<?php

use App\Models\Member;
use App\Models\User;
use Database\Seeders\WorkspaceSuperadminSeeder;

it('seeds a super-admin user and linked member from config', function () {
    config([
        'project-management.super_admins' => ['boss@example.com'],
        'project-management.super_admin_default_password' => 'seeded-pass-1',
    ]);

    $this->seed(WorkspaceSuperadminSeeder::class);

    $user = User::query()->where('email', 'boss@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->can('manage-workspace'))->toBeTrue()
        ->and(Member::query()->where('user_id', $user->id)->exists())->toBeTrue();

    // Idempotent: a second run does not duplicate.
    $this->seed(WorkspaceSuperadminSeeder::class);

    expect(User::query()->where('email', 'boss@example.com')->count())->toBe(1)
        ->and(Member::query()->where('user_id', $user->id)->count())->toBe(1);
});
