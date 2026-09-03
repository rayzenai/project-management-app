<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

beforeEach(fn () => config(['project-management.super_admins' => ['boss@example.com']]));

it('lets a leader create a project scoped to a team they lead', function () {
    $leaderUser = User::factory()->create();
    $leader = Member::factory()->linkedTo($leaderUser)->create();
    $team = Team::factory()->create();
    $team->members()->attach($leader->id, ['role' => 'leader']);

    $this->actingAs($leaderUser)->post('/workspace/projects', [
        'title' => 'Roadmap',
        'team_ids' => [$team->id],
    ])->assertRedirect();

    $project = Project::query()->where('title', 'Roadmap')->firstOrFail();
    expect($project->teams()->pluck('teams.id'))->toContain($team->id)
        ->and($project->is_public)->toBeFalse();
});

it('rejects attaching a team the leader does not lead', function () {
    $leaderUser = User::factory()->create();
    $leader = Member::factory()->linkedTo($leaderUser)->create();
    Team::factory()->create()->members()->attach($leader->id, ['role' => 'leader']);
    $foreign = Team::factory()->create();

    $this->from('/workspace/projects')->actingAs($leaderUser)->post('/workspace/projects', [
        'title' => 'X', 'team_ids' => [$foreign->id],
    ])->assertSessionHasErrors('team_ids');
});

it('requires teams unless the project is public', function () {
    $leaderUser = User::factory()->create();
    $leader = Member::factory()->linkedTo($leaderUser)->create();
    Team::factory()->create()->members()->attach($leader->id, ['role' => 'leader']);

    $this->from('/workspace/projects')->actingAs($leaderUser)->post('/workspace/projects', [
        'title' => 'No team',
    ])->assertSessionHasErrors('team_ids');
});

it('forbids a plain member from creating a project', function () {
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();

    $this->actingAs($user)->post('/workspace/projects', ['title' => 'Nope'])->assertForbidden();
});

it('ignores is_public from a non-super-admin', function () {
    $leaderUser = User::factory()->create();
    $leader = Member::factory()->linkedTo($leaderUser)->create();
    $team = Team::factory()->create();
    $team->members()->attach($leader->id, ['role' => 'leader']);

    $this->actingAs($leaderUser)->post('/workspace/projects', [
        'title' => 'Sneaky', 'team_ids' => [$team->id], 'is_public' => true,
    ])->assertRedirect();

    expect(Project::query()->where('title', 'Sneaky')->firstOrFail()->is_public)->toBeFalse();
});

it('lets a super-admin create a public project with no teams', function () {
    $admin = User::factory()->create(['email' => 'boss@example.com']);

    $this->actingAs($admin)->post('/workspace/projects', [
        'title' => 'Announce', 'is_public' => true,
    ])->assertRedirect();

    expect(Project::query()->where('title', 'Announce')->firstOrFail()->is_public)->toBeTrue();
});
