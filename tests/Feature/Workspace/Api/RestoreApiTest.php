<?php

use App\Models\Project;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
});

it('lets a subtask owner restore their own soft-deleted subtask', function () {
    $owner = User::factory()->withMember()->create();
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
    $owner = User::factory()->withMember()->create();

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

    Sanctum::actingAs(User::factory()->withMember()->create(), ['*']);

    $this->postJson("/api/v1/workspace/subtasks/{$subtask->id}/restore")->assertForbidden();

    expect($subtask->fresh()->trashed())->toBeTrue();
});

it('restores a soft-deleted task under its correct project slug', function () {
    Sanctum::actingAs(User::factory()->withMember()->create(), ['*']);

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
    Sanctum::actingAs(User::factory()->withMember()->create(), ['*']);

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
