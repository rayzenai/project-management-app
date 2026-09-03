<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;

beforeEach(fn () => config(['project-management.super_admins' => ['boss@example.com']]));

it('forbids showing a private project to a non-member', function () {
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();
    $project = Project::factory()->create(['is_public' => false]);
    $project->teams()->attach(Team::factory()->create()->id);

    $this->actingAs($user)->get("/workspace/projects/{$project->slug}")->assertForbidden();
});

it('allows showing a project to a member of its team', function () {
    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create();
    $team = Team::factory()->create();
    $team->members()->attach($member->id, ['role' => 'member']);
    $project = Project::factory()->create(['is_public' => false]);
    $project->teams()->attach($team->id);

    $this->actingAs($user)->get("/workspace/projects/{$project->slug}")->assertOk();
});

it('forbids showing a task whose project is not visible (API)', function () {
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();
    $project = Project::factory()->create(['is_public' => false]);
    $project->teams()->attach(Team::factory()->create()->id);
    $task = Task::factory()->create(['project_id' => $project->id]);

    $this->actingAs($user)->getJson("/api/v1/workspace/projects/{$project->slug}/tasks/{$task->slug}")
        ->assertForbidden();
});
