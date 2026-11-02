<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

/*
 * One rule everywhere: a task is overdue when it is still being worked on
 * (not done, not failed) and its deadline has passed.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    CarbonImmutable::setTestNow('2026-10-28 10:00:00');

    $this->project = Project::factory()->create();
    $this->user = User::factory()->create();
    $this->member = Member::factory()->linkedTo($this->user)->create(['organization_id' => $this->project->organization_id]);
    $this->project->ensureTeam()->members()->attach($this->member->id);

    $make = function (string $status, ?string $deadline): Task {
        $task = Task::factory()->create(['project_id' => $this->project->id, 'status' => $status, 'deadline_at' => $deadline]);
        ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $this->member->id]);

        return $task;
    };

    $this->late = $make('in_progress', '2026-10-20');
    $this->failed = $make('failed', '2026-10-20');
    $this->doneLate = $make('done', '2026-10-20');
    $this->upcoming = $make('not_started', '2026-11-05');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('marks only open work past its deadline as overdue', function () {
    expect($this->late->fresh()->is_overdue)->toBeTrue()
        ->and($this->failed->fresh()->is_overdue)->toBeFalse()
        ->and($this->doneLate->fresh()->is_overdue)->toBeFalse()
        ->and($this->upcoming->fresh()->is_overdue)->toBeFalse()
        ->and(Task::query()->overdue()->pluck('id')->all())->toBe([$this->late->id]);
});

it('asks the same rule of another day', function () {
    $friday = CarbonImmutable::parse('2026-11-06');

    expect($this->upcoming->fresh()->isOverdueOn($friday))->toBeTrue()
        ->and($this->upcoming->fresh()->isOverdueOn(CarbonImmutable::parse('2026-11-05')))->toBeFalse()
        ->and($this->late->fresh()->isOverdueOn($friday))->toBeTrue()
        ->and($this->failed->fresh()->isOverdueOn($friday))->toBeFalse()
        ->and($this->doneLate->fresh()->isOverdueOn($friday))->toBeFalse();
});

it('counts the same overdue work on the project page, the projects list and My work', function () {
    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']));

    $this->get("/workspace/projects/{$this->project->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tasks', fn ($tasks) => collect($tasks)->where('is_overdue', true)->pluck('id')->all() === [$this->late->id]));

    $this->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('projects.0.overdue_tasks_count', 1));

    // My work lists the open work only, and calls only the overdue one late.
    $rows = collect($this->actingAs($this->user)->get('/workspace/my')->assertOk()->viewData('page')['props']['groups'])
        ->flatMap(fn (array $group): array => $group['rows']);

    expect($rows->pluck('task.id')->sort()->values()->all())->toBe([$this->late->id, $this->upcoming->id])
        ->and($rows->where('days_late', '>', 0)->pluck('task.id')->all())->toBe([$this->late->id]);
});
