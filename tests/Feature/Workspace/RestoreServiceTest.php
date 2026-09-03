<?php

use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Workspace\RestoreWorkspaceModel;

it('restores a trashed model and returns a success result', function () {
    $task = Task::factory()->create();
    $task->delete();

    $result = app(RestoreWorkspaceModel::class)->execute($task);

    expect($result->success)->toBeTrue()
        ->and($result->message)->toBe('Restored.')
        ->and($task->fresh()->trashed())->toBeFalse();
});

it('is a safe no-op when the model is not trashed', function () {
    $task = Task::factory()->create();

    $result = app(RestoreWorkspaceModel::class)->execute($task);

    expect($result->success)->toBeTrue()
        ->and($result->message)->toBe('Already restored.')
        ->and($task->fresh()->trashed())->toBeFalse();
});

it('restores a task with its original slug intact when the slug is free', function () {
    $task = Task::factory()->create();
    $slug = $task->slug;
    $task->delete();

    app(RestoreWorkspaceModel::class)->execute($task);

    expect($task->fresh()->slug)->toBe($slug)
        ->and($task->fresh()->trashed())->toBeFalse();
});

it('appends a numeric suffix when the slug is reused while trashed', function () {
    $original = Task::factory()->create();
    $slug = $original->slug;
    $original->delete();

    Task::factory()->create(['slug' => $slug, 'project_id' => $original->project_id]);

    app(RestoreWorkspaceModel::class)->execute($original);

    expect($original->fresh()->slug)->not->toBe($slug)
        ->and($original->fresh()->trashed())->toBeFalse();
})->skip(
    'Unreachable under the current schema: tasks.slug is globally UNIQUE '.
    '(including trashed rows), so a live row can never reuse a trashed slug — '.
    'the duplicate insert is rejected at the DB level during setup. The '.
    'ensureUniqueSlug guard is retained to protect a future partial-index '.
    'schema and any slugged model whose uniqueness is not DB-enforced.'
);

it('no-ops the slug guard for a slugless model', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $subtask = Subtask::create(['task_id' => $task->id, 'user_id' => $user->id, 'body' => 'Do it', 'position' => 1]);
    $subtask->delete();

    $result = app(RestoreWorkspaceModel::class)->execute($subtask);

    expect($result->success)->toBeTrue()
        ->and($subtask->fresh()->trashed())->toBeFalse();
});
