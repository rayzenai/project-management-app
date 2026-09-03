<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\WorkspaceAccess;

it('redirects guests to the workspace login', function (string $uri) {
    $this->get($uri)->assertRedirect('/workspace/login');
})->with(['/workspace', '/workspace/my']);

it('renders the workspace for an authenticated user', function (string $uri) {
    $this->actingAs(User::factory()->create());

    $this->get($uri)->assertSuccessful();
})->with(['/workspace', '/workspace/my']);

it('resolves roles through WorkspaceAccess', function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $super = User::factory()->create(['email' => 'boss@example.com']);
    $leaderUser = User::factory()->create();
    $stranger = User::factory()->create();

    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $leaderMember = Member::factory()->linkedTo($leaderUser)->create();
    $team->members()->attach($leaderMember->id, ['role' => 'leader']);

    $project = Project::factory()->create();
    $project->teams()->attach($team->id);
    $otherProject = Project::factory()->create();
    $otherProject->teams()->attach($otherTeam->id);

    expect(WorkspaceAccess::isSuperAdmin($super))->toBeTrue()
        ->and(WorkspaceAccess::isSuperAdmin($leaderUser))->toBeFalse()
        ->and(WorkspaceAccess::leadsTeam($leaderUser, $team))->toBeTrue()
        ->and(WorkspaceAccess::leadsTeam($leaderUser, $otherTeam))->toBeFalse()
        ->and(WorkspaceAccess::canManageRosterOf($super, $otherTeam))->toBeTrue()
        ->and(WorkspaceAccess::canManageRosterOf($leaderUser, $team))->toBeTrue()
        ->and(WorkspaceAccess::canManageRosterOf($stranger, $team))->toBeFalse()
        ->and(WorkspaceAccess::canArchiveProject($leaderUser, $project))->toBeTrue()
        ->and(WorkspaceAccess::canArchiveProject($leaderUser, $otherProject))->toBeFalse()
        ->and(WorkspaceAccess::canArchiveProject($super, $otherProject))->toBeTrue()
        ->and(WorkspaceAccess::ledTeamIds($leaderUser))->toBe([$team->id]);
});

it('scopes member creation and management through WorkspaceAccess', function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $super = User::factory()->create(['email' => 'boss@example.com']);
    $leaderUser = User::factory()->create();

    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $leaderMember = Member::factory()->linkedTo($leaderUser)->create();
    $teamA->members()->attach($leaderMember->id, ['role' => 'leader']);

    $onTeamA = Member::factory()->create();
    $teamA->members()->attach($onTeamA->id);
    $onTeamB = Member::factory()->create();
    $teamB->members()->attach($onTeamB->id);

    // canCreateMemberForTeams
    expect(WorkspaceAccess::canCreateMemberForTeams($super, []))->toBeTrue()
        ->and(WorkspaceAccess::canCreateMemberForTeams($leaderUser, []))->toBeFalse()
        ->and(WorkspaceAccess::canCreateMemberForTeams($leaderUser, [$teamA->id]))->toBeTrue()
        ->and(WorkspaceAccess::canCreateMemberForTeams($leaderUser, [$teamA->id, $teamB->id]))->toBeFalse()
        // canManageMember
        ->and(WorkspaceAccess::canManageMember($super, $onTeamB))->toBeTrue()
        ->and(WorkspaceAccess::canManageMember($leaderUser, $onTeamA))->toBeTrue()
        ->and(WorkspaceAccess::canManageMember($leaderUser, $onTeamB))->toBeFalse();
});
