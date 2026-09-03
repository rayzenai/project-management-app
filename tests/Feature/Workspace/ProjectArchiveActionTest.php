<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
});

it('lets a super-admin archive and restore any project', function () {
    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']));
    $project = Project::factory()->create();

    $this->patch("/workspace/projects/{$project->slug}/archive")->assertRedirect();
    expect($project->fresh()->is_archived)->toBeTrue();

    $this->patch("/workspace/projects/{$project->slug}/restore")->assertRedirect();
    expect($project->fresh()->is_archived)->toBeFalse();
});

it('lets a leader archive a project on a team they lead', function () {
    $user = User::factory()->create();
    $leader = Member::factory()->linkedTo($user)->create();
    $team = Team::factory()->create();
    $team->members()->attach($leader->id, ['role' => 'leader']);
    $project = Project::factory()->create();
    $project->teams()->attach($team->id);

    $this->actingAs($user)
        ->patch("/workspace/projects/{$project->slug}/archive")
        ->assertRedirect();

    expect($project->fresh()->is_archived)->toBeTrue();
});

it('forbids archiving a project you have no team leadership over', function () {
    $this->actingAs(User::factory()->create());
    $project = Project::factory()->create();

    $this->patch("/workspace/projects/{$project->slug}/archive")->assertForbidden();

    expect($project->fresh()->is_archived)->toBeFalse();
});
