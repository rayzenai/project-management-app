<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

beforeEach(fn () => config(['project-management.super_admins' => ['boss@example.com']]));

it('lets a leader of an attached team change its teams', function () {
    $leaderUser = User::factory()->create();
    $leader = Member::factory()->linkedTo($leaderUser)->create();
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();
    $teamA->members()->attach($leader->id, ['role' => 'leader']);
    $teamB->members()->attach($leader->id, ['role' => 'leader']);

    $project = Project::factory()->create();
    $project->teams()->attach($teamA->id);

    $this->actingAs($leaderUser)->patch("/workspace/projects/{$project->slug}", [
        'team_ids' => [$teamA->id, $teamB->id],
    ])->assertRedirect();

    expect($project->fresh()->teams()->pluck('teams.id'))->toContain($teamB->id);
});

it('forbids updating a project the user cannot manage', function () {
    $leaderUser = User::factory()->create();
    $leader = Member::factory()->linkedTo($leaderUser)->create();
    Team::factory()->create()->members()->attach($leader->id, ['role' => 'leader']);

    $project = Project::factory()->create();
    $project->teams()->attach(Team::factory()->create()->id);

    $this->actingAs($leaderUser)->patch("/workspace/projects/{$project->slug}", ['title' => 'X'])
        ->assertForbidden();
});

it('forbids a leader from granting access to a team they do not lead', function () {
    $leaderUser = User::factory()->create();
    $leader = Member::factory()->linkedTo($leaderUser)->create();
    $teamA = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $teamA->members()->attach($leader->id, ['role' => 'leader']);

    $project = Project::factory()->create();
    $project->teams()->attach($teamA->id);

    $this->actingAs($leaderUser)->patch("/workspace/projects/{$project->slug}", [
        'team_ids' => [$teamA->id, $otherTeam->id],
    ])->assertSessionHasErrors('team_ids');

    expect($project->fresh()->teams()->pluck('teams.id')->all())->toBe([$teamA->id]);
});

it('lets a super-admin set is_public on update', function () {
    $adminUser = User::factory()->create(['email' => 'boss@example.com']);
    Member::factory()->linkedTo($adminUser)->create();
    $project = Project::factory()->create(['is_public' => false]);

    $this->actingAs($adminUser)->patch("/workspace/projects/{$project->slug}", ['is_public' => true])
        ->assertRedirect();

    expect($project->fresh()->is_public)->toBeTrue();
});

it('does not let a leader toggle is_public', function () {
    $leaderUser = User::factory()->create();
    $leader = Member::factory()->linkedTo($leaderUser)->create();
    $team = Team::factory()->create();
    $team->members()->attach($leader->id, ['role' => 'leader']);
    $project = Project::factory()->create(['is_public' => false]);
    $project->teams()->attach($team->id);

    $this->actingAs($leaderUser)->patch("/workspace/projects/{$project->slug}", ['is_public' => true])
        ->assertRedirect();

    expect($project->fresh()->is_public)->toBeFalse();
});
