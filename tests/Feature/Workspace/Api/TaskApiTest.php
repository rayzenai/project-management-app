<?php

use App\Models\Project;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user, ['*']);
    // Public so the actor (a plain, non-team member) can see the project and
    // therefore act on its tasks — this suite exercises task CRUD, not visibility,
    // which is covered by TaskVisibilityGuardTest.
    $this->project = Project::factory()->public()->create();
});

it('creates a task under a project', function () {
    $response = $this->postJson("/api/v1/workspace/projects/{$this->project->slug}/tasks", [
        'title' => 'Survey the site',
        'description' => 'Initial walk-through.',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['message', 'data' => ['id', 'slug', 'title']])
        ->assertJsonPath('data.title', 'Survey the site');

    expect(Task::query()->where('title', 'Survey the site')->where('project_id', $this->project->id)->exists())->toBeTrue();
});

it('rejects creating a task with no title', function () {
    $this->postJson("/api/v1/workspace/projects/{$this->project->slug}/tasks", [
        'description' => 'orphan',
    ])->assertUnprocessable()
        ->assertJsonStructure(['errors'])
        ->assertJsonValidationErrors('title');
});

it('updates a task', function () {
    $slug = $this->postJson("/api/v1/workspace/projects/{$this->project->slug}/tasks", [
        'title' => 'Original title',
    ])->json('data.slug');

    $this->patchJson("/api/v1/workspace/projects/{$this->project->slug}/tasks/{$slug}", [
        'title' => 'Updated title',
    ])->assertOk()
        ->assertJsonPath('data.title', 'Updated title');
});

it('deletes a task', function () {
    $slug = $this->postJson("/api/v1/workspace/projects/{$this->project->slug}/tasks", [
        'title' => 'Disposable',
    ])->json('data.slug');

    $task = Task::query()->where('slug', $slug)->firstOrFail();

    $this->deleteJson("/api/v1/workspace/projects/{$this->project->slug}/tasks/{$slug}")
        ->assertOk()
        ->assertJsonStructure(['message']);

    expect(Task::query()->find($task->id))->toBeNull();
});

it('creates a personal subtask on a task', function () {
    $task = Task::query()->create([
        'project_id' => $this->project->id,
        'title' => 'Parent task',
        'slug' => 'parent-task-1',
        'status' => 'unclear',
        'item_number' => 1,
    ]);

    $response = $this->postJson("/api/v1/workspace/tasks/{$task->id}/subtasks", [
        'body' => 'My personal todo',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['message', 'data' => ['id', 'task_id', 'user_id', 'body']])
        ->assertJsonPath('data.user_id', $this->user->id)
        ->assertJsonPath('data.body', 'My personal todo');
});

it('forbids another user from updating or deleting someone else\'s subtask', function () {
    $task = Task::query()->create([
        'project_id' => $this->project->id,
        'title' => 'Shared task',
        'slug' => 'shared-task-1',
        'status' => 'unclear',
        'item_number' => 1,
    ]);

    $subtaskId = $this->postJson("/api/v1/workspace/tasks/{$task->id}/subtasks", [
        'body' => 'Owner only',
    ])->json('data.id');

    Sanctum::actingAs(User::factory()->create(), ['*']);

    $this->patchJson("/api/v1/workspace/subtasks/{$subtaskId}", ['body' => 'hijacked'])
        ->assertForbidden();
    $this->deleteJson("/api/v1/workspace/subtasks/{$subtaskId}")
        ->assertForbidden();

    expect(Subtask::query()->find($subtaskId)->body)->toBe('Owner only');
});
