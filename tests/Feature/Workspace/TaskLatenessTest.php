<?php

use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Support\Facades\Schema;

it('has a completed_at column on tasks', function () {
    expect(Schema::hasColumn('tasks', 'completed_at'))->toBeTrue();
});

it('stamps completed_at when a task becomes done', function () {
    $task = Task::factory()->create(['status' => 'in_progress', 'completed_at' => null]);

    $task->update(['status' => 'done']);

    expect($task->fresh()->completed_at)->not->toBeNull();
});

it('clears completed_at when a done task is reopened', function () {
    $task = Task::factory()->create(['status' => 'done']);
    expect($task->fresh()->completed_at)->not->toBeNull();

    $task->update(['status' => 'in_progress']);

    expect($task->fresh()->completed_at)->toBeNull();
});

it('does not overwrite an explicitly seeded completed_at', function () {
    $when = now()->subDays(10)->startOfDay();
    $task = Task::factory()->create(['status' => 'done', 'completed_at' => $when]);

    expect($task->fresh()->completed_at->toDateString())->toBe($when->toDateString());
});

it('flags is_late for an incomplete overdue task', function () {
    $task = Task::factory()->create(['status' => 'in_progress', 'deadline_at' => now()->subDay()]);

    expect($task->is_late)->toBeTrue();
});

it('does not flag is_late for an incomplete task due today or future', function () {
    $today = Task::factory()->create(['status' => 'in_progress', 'deadline_at' => now()]);
    $future = Task::factory()->create(['status' => 'in_progress', 'deadline_at' => now()->addDays(5)]);

    expect($today->is_late)->toBeFalse()
        ->and($future->is_late)->toBeFalse();
});

it('flags is_late for a task completed after its deadline', function () {
    $task = Task::factory()->create([
        'status' => 'done',
        'deadline_at' => now()->subDays(5),
        'completed_at' => now()->subDay(),
    ]);

    expect($task->is_late)->toBeTrue();
});

it('does not flag is_late for a task completed on or before its deadline', function () {
    $onTime = Task::factory()->create([
        'status' => 'done',
        'deadline_at' => now()->addDays(2),
        'completed_at' => now(),
    ]);

    expect($onTime->is_late)->toBeFalse();
});

it('does not flag is_late when there is no deadline', function () {
    $task = Task::factory()->create([
        'status' => 'in_progress',
        'deadline_at' => null,
        'deadline_type' => null,
    ]);

    expect($task->is_late)->toBeFalse();
});

it('exposes completed_at and is_late in the task resource', function () {
    $task = Task::factory()->create([
        'status' => 'done',
        'deadline_at' => now()->subDays(5),
        'completed_at' => now()->subDay(),
    ]);

    $array = (new TaskResource($task))
        ->toArray(request());

    expect($array)->toHaveKeys(['completed_at', 'is_late'])
        ->and($array['is_late'])->toBeTrue()
        ->and($array['completed_at'])->not->toBeNull();
});
