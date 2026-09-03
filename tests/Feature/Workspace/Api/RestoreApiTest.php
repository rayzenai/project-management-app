<?php

use App\Models\Project;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
});

it('restores a soft-deleted team for a super admin', function () {
    $admin = User::factory()->create(['email' => 'boss@example.com']);
    Sanctum::actingAs($admin, ['*']);

    $team = Team::factory()->create();
    $team->delete();

    $this->postJson("/api/v1/workspace/teams/{$team->id}/restore")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $team->id);

    expect($team->fresh()->trashed())->toBeFalse();
});

it('forbids a non-admin from restoring a team', function () {
    Sanctum::actingAs(User::factory()->create(), ['*']);

    $team = Team::factory()->create();
    $team->delete();

    $this->postJson("/api/v1/workspace/teams/{$team->id}/restore")->assertForbidden();

    expect($team->fresh()->trashed())->toBeTrue();
});

it('lets a subtask owner restore their own soft-deleted subtask', function () {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner, ['*']);

    $project = Project::factory()->public()->create();
    $task = Task::query()->create([
        'project_id' => $project->id,
        'title' => 'Parent task',
        'slug' => 'parent-task-1',
        'status' => 'unclear',
        'item_number' => 1,
    ]);
    $subtask = Subtask::create(['task_id' => $task->id, 'user_id' => $owner->id, 'body' => 'Do it', 'position' => 1]);
    $subtask->delete();

    $this->postJson("/api/v1/workspace/subtasks/{$subtask->id}/restore")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $subtask->id);

    expect($subtask->fresh()->trashed())->toBeFalse();
});

it('forbids a different user from restoring someone else\'s subtask', function () {
    $owner = User::factory()->create();

    $project = Project::factory()->public()->create();
    $task = Task::query()->create([
        'project_id' => $project->id,
        'title' => 'Parent task',
        'slug' => 'parent-task-1',
        'status' => 'unclear',
        'item_number' => 1,
    ]);
    $subtask = Subtask::create(['task_id' => $task->id, 'user_id' => $owner->id, 'body' => 'Do it', 'position' => 1]);
    $subtask->delete();

    Sanctum::actingAs(User::factory()->create(), ['*']);

    $this->postJson("/api/v1/workspace/subtasks/{$subtask->id}/restore")->assertForbidden();

    expect($subtask->fresh()->trashed())->toBeTrue();
});

it('restores a soft-deleted task under its correct project slug', function () {
    Sanctum::actingAs(User::factory()->create(), ['*']);

    $project = Project::factory()->public()->create();
    $task = Task::query()->create([
        'project_id' => $project->id,
        'title' => 'Disposable task',
        'slug' => 'disposable-task-1',
        'status' => 'unclear',
        'item_number' => 1,
    ]);
    $task->delete();

    $this->postJson("/api/v1/workspace/projects/{$project->slug}/tasks/{$task->slug}/restore")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $task->id);

    expect($task->fresh()->trashed())->toBeFalse();
});

it('returns 404 when restoring a task under the wrong project slug', function () {
    Sanctum::actingAs(User::factory()->create(), ['*']);

    $projectA = Project::factory()->public()->create();
    $projectB = Project::factory()->public()->create();
    $task = Task::query()->create([
        'project_id' => $projectA->id,
        'title' => 'Belongs to A',
        'slug' => 'belongs-to-a-1',
        'status' => 'unclear',
        'item_number' => 1,
    ]);
    $task->delete();

    $this->postJson("/api/v1/workspace/projects/{$projectB->slug}/tasks/{$task->slug}/restore")
        ->assertNotFound();

    expect($task->fresh()->trashed())->toBeTrue();
});
