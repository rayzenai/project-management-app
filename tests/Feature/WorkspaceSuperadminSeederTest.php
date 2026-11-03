<?php

use App\Models\Member;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Database\Seeders\WorkspaceSuperadminSeeder;
use Illuminate\Support\Facades\Gate;

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

/**
 * Reads config/project-management.php again as production would with
 * PM_SUPER_ADMINS set to `$value`, then puts the environment back.
 */
function superAdminsFromEnv(string $value): void
{
    $server = $_SERVER['PM_SUPER_ADMINS'] ?? null;
    $env = $_ENV['PM_SUPER_ADMINS'] ?? null;
    $_SERVER['PM_SUPER_ADMINS'] = $_ENV['PM_SUPER_ADMINS'] = $value;

    try {
        config(['project-management' => require config_path('project-management.php')]);
    } finally {
        unset($_SERVER['PM_SUPER_ADMINS'], $_ENV['PM_SUPER_ADMINS']);

        if ($server !== null) {
            $_SERVER['PM_SUPER_ADMINS'] = $server;
        }

        if ($env !== null) {
            $_ENV['PM_SUPER_ADMINS'] = $env;
        }
    }
}

it('keeps every super-admin power for a PM_SUPER_ADMINS entry with capitals, now that emails are stored lowercase', function () {
    superAdminsFromEnv(' Boss@Example.com , second@example.com');

    expect(config('project-management.super_admins'))->toBe(['boss@example.com', 'second@example.com']);

    $boss = User::factory()->create(['email' => 'Boss@Example.com']);
    $other = User::factory()->create(['email' => 'someone@example.com']);

    expect($boss->email)->toBe('boss@example.com')
        ->and(Gate::forUser($boss)->allows('manage-workspace'))->toBeTrue()
        ->and(WorkspaceAccess::isSuperAdmin($boss))->toBeTrue()
        ->and(WorkspaceAccess::isSuperAdminEmail('BOSS@example.com'))->toBeTrue()
        ->and(Gate::forUser($other)->allows('manage-workspace'))->toBeFalse();
});

it('seeds a PM_SUPER_ADMINS entry with capitals once, however often it runs', function () {
    superAdminsFromEnv('Boss@Example.com');

    $this->seed(WorkspaceSuperadminSeeder::class);
    $this->seed(WorkspaceSuperadminSeeder::class);

    $user = User::query()->where('email', 'boss@example.com')->sole();

    expect(User::query()->count())->toBe(1)
        ->and($user->can('manage-workspace'))->toBeTrue()
        ->and(Member::query()->where('user_id', $user->id)->count())->toBe(1);
});
