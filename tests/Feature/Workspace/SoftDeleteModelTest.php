<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectContact;
use App\Models\ProjectNote;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkspaceNote;
use App\Services\Workspace\CreateTaskService;

it('soft-deletes a workspace model instead of removing the row', function (string $modelClass, callable $make) {
    $model = $make();

    $model->delete();

    expect($modelClass::find($model->getKey()))->toBeNull()
        ->and($modelClass::withTrashed()->find($model->getKey()))->not->toBeNull()
        ->and($modelClass::withTrashed()->find($model->getKey())->trashed())->toBeTrue();
})->with([
    'Task' => [Task::class, fn () => Task::factory()->create()],
    'Subtask' => [Subtask::class, function () {
        $user = User::factory()->create();
        $task = Task::factory()->create();

        return Subtask::create(['task_id' => $task->id, 'user_id' => $user->id, 'body' => 'Do it', 'position' => 1]);
    }],
    'ProjectNote' => [ProjectNote::class, function () {
        $user = User::factory()->create();
        $task = Task::factory()->create();

        return ProjectNote::create(['task_id' => $task->id, 'user_id' => $user->id, 'type' => 'general', 'body' => 'A note']);
    }],
    'ProjectContact' => [ProjectContact::class, function () {
        $user = User::factory()->create();
        $task = Task::factory()->create();

        return ProjectContact::create(['task_id' => $task->id, 'user_id' => $user->id, 'name' => 'Jane']);
    }],
    'ProjectAssignment' => [ProjectAssignment::class, function () {
        $task = Task::factory()->create();
        $member = Member::factory()->create();

        return ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $member->id]);
    }],
    'WorkspaceNote' => [WorkspaceNote::class, function () {
        $user = User::factory()->create();

        return WorkspaceNote::create(['user_id' => $user->id, 'body' => 'Sticky', 'color' => 'amber']);
    }],
    'Team' => [Team::class, fn () => Team::factory()->create()],
    'Member' => [Member::class, fn () => Member::factory()->create()],
]);

it('does not reuse a soft-deleted team slug', function () {
    $team = Team::factory()->create(['name' => 'Alpha', 'slug' => null]);
    $slug = $team->slug;
    $team->delete();

    $new = Team::factory()->create(['name' => 'Alpha', 'slug' => null]);

    expect($new->slug)->not->toBe($slug);
});

it('does not reuse the item_number of a soft-deleted task in the same project', function () {
    $project = Project::factory()->create();
    $service = app(CreateTaskService::class);

    $first = $service->execute($project, ['title' => 'Same Title'])->data;
    $first->delete();

    $second = $service->execute($project, ['title' => 'Same Title'])->data;

    expect($second)->not->toBeNull()
        ->and($second->item_number)->toBeGreaterThan($first->item_number);
});

it('creates a task with an explicit item_number reused from a soft-deleted same-titled task', function () {
    $project = Project::factory()->create();
    $service = app(CreateTaskService::class);

    $first = $service->execute($project, ['title' => 'Reused Title', 'item_number' => 5]);
    expect($first->success)->toBeTrue();
    $first->data->delete();

    $second = $service->execute($project, ['title' => 'Reused Title', 'item_number' => 5]);

    expect($second->success)->toBeTrue()
        ->and($second->data)->not->toBeNull()
        ->and($second->data->id)->not->toBe($first->data->id);
});
