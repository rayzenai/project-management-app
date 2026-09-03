<?php

use App\Models\Task;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('knows whether a task status counts as complete', function (string $status, bool $complete) {
    $task = Task::factory()->create(['status' => $status]);

    expect($task->isComplete())->toBe($complete);
})->with([
    'done' => ['done', true],
    'in progress' => ['in_progress', false],
    'not started' => ['not_started', false],
    'failed' => ['failed', false],
]);

it('scopes tasks by completion', function () {
    Task::factory()->create(['status' => 'done']);
    Task::factory()->create(['status' => 'in_progress']);
    Task::factory()->create(['status' => 'failed']);

    expect(Task::query()->complete()->count())->toBe(1)
        ->and(Task::query()->incomplete()->count())->toBe(2);
});

it('derives the complete status list from the package config', function () {
    expect(Task::completeStatuses())->toBe(['done']);

    config()->set('project-management.statuses.in_progress.is_complete', true);

    expect(Task::completeStatuses())->toContain('in_progress');
});

it('reads status label and color from the package config, not government config', function () {
    config()->set('project-management.statuses.in_progress', [
        'label' => 'Cooking',
        'color' => '#123456',
        'is_complete' => false,
    ]);
    config()->set('government.statuses.in_progress', ['label' => 'WRONG', 'color' => '#ffffff']);

    $task = Task::factory()->create(['status' => 'in_progress']);

    expect($task->status_label)->toBe('Cooking')
        ->and($task->status_color)->toBe('#123456');
});

it('shares ordered statuses with completion flags on every workspace page', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/workspace')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('statuses', 5)
        ->where('statuses.0.value', 'not_started')
        ->where('statuses.0.is_complete', false)
        ->where('statuses.3.value', 'done')
        ->where('statuses.3.is_complete', true)
        ->where('statuses.4.value', 'failed')
        ->has('statuses.0.label')
        ->has('statuses.0.color'));
});

it('exposes the configured one-click complete status', function () {
    expect(config('project-management.complete_status'))->toBe('done');
});
