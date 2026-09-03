<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\WorkspaceAccess;

beforeEach(fn () => config(['project-management.super_admins' => ['boss@example.com']]));

it('canViewProject: public, shared-team, super-admin true; stranger false', function () {
    $admin = User::factory()->create(['email' => 'boss@example.com']);
    $member = User::factory()->create();
    $stranger = User::factory()->create();

    $m = Member::factory()->linkedTo($member)->create();
    $team = Team::factory()->create();
    $team->members()->attach($m->id, ['role' => 'member']);

    $project = Project::factory()->create(['is_public' => false]);
    $project->teams()->attach($team->id);
    $public = Project::factory()->create(['is_public' => true]);

    expect(WorkspaceAccess::canViewProject($member, $project))->toBeTrue()
        ->and(WorkspaceAccess::canViewProject($admin, $project))->toBeTrue()
        ->and(WorkspaceAccess::canViewProject($stranger, $project))->toBeFalse()
        ->and(WorkspaceAccess::canViewProject($stranger, $public))->toBeTrue();
});

it('canCreateProject: super-admin or a team leader only', function () {
    $admin = User::factory()->create(['email' => 'boss@example.com']);
    $leaderUser = User::factory()->create();
    $plain = User::factory()->create();

    $leader = Member::factory()->linkedTo($leaderUser)->create();
    Team::factory()->create()->members()->attach($leader->id, ['role' => 'leader']);

    expect(WorkspaceAccess::canCreateProject($admin))->toBeTrue()
        ->and(WorkspaceAccess::canCreateProject($leaderUser))->toBeTrue()
        ->and(WorkspaceAccess::canCreateProject($plain))->toBeFalse();
});

it('canManageProjectAccess: leader of an attached team or super-admin', function () {
    $leaderUser = User::factory()->create();
    $leader = Member::factory()->linkedTo($leaderUser)->create();
    $team = Team::factory()->create();
    $team->members()->attach($leader->id, ['role' => 'leader']);

    $managed = Project::factory()->create();
    $managed->teams()->attach($team->id);
    $other = Project::factory()->create();
    $other->teams()->attach(Team::factory()->create()->id);

    expect(WorkspaceAccess::canManageProjectAccess($leaderUser, $managed))->toBeTrue()
        ->and(WorkspaceAccess::canManageProjectAccess($leaderUser, $other))->toBeFalse();
});
