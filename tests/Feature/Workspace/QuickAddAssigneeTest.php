<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;

it('creates an unassigned task when nobody is picked and the user is not on the project team', function () {
    config()->set('project-management.super_admins', ['boss@example.com']);
    $user = User::factory()->create(['email' => 'boss@example.com']); // super-admin: can view any project
    $this->actingAs($user);

    $project = Project::factory()->create();
    $team = Team::factory()->create();
    $project->teams()->attach($team->id);
    $teamMember = Member::factory()->create(['is_active' => true]);
    $team->members()->attach($teamMember->id); // someone else is on the team; the user is not

    $this->post('/workspace/quick-add', [
        'project_id' => $project->id,
        'title' => 'test task',
    ])->assertRedirect();

    $task = Task::query()->where('title', 'test task')->first();

    expect($task)->not->toBeNull()
        ->and($task->project_id)->toBe($project->id)
        ->and($task->assignments()->count())->toBe(0);
});

it('defaults the assignee to self when the user is on the project team and picks nobody', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $member = Member::forUser($user);

    $project = Project::factory()->create();
    $team = Team::factory()->create();
    $project->teams()->attach($team->id);
    $team->members()->attach($member->id); // the user IS on the team

    $this->post('/workspace/quick-add', [
        'project_id' => $project->id,
        'title' => 'mine',
    ])->assertRedirect();

    $task = Task::query()->where('title', 'mine')->first();

    expect($task)->not->toBeNull()
        ->and($task->assignments()->count())->toBe(1)
        ->and($task->assignments()->first()->member_id)->toBe($member->id);
});
