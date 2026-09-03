<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    $this->user = User::factory()->create(['email' => 'boss@example.com']);
    $this->actingAs($this->user);
    $this->member = Member::forUser($this->user);
});

it('omits archived projects from the projects index by default and lists them under the archived view', function () {
    $active = Project::factory()->create(['title' => 'Active One']);
    $archived = Project::factory()->create(['title' => 'Archived One']);
    $archived->archive();

    $this->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('projects', fn ($projects) => collect($projects)->pluck('title')->all() === ['Active One'])
            ->where('archivedCount', 1));

    $this->get('/workspace/projects?archived=1')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('projects', fn ($projects) => collect($projects)->pluck('title')->all() === ['Archived One']));
});

it('drops archived projects from the My Workspace picker and dormant-tasks from assignments', function () {
    $active = Project::factory()->create();
    $archived = Project::factory()->create();

    $activeTask = Task::factory()->for($active)->create(['status' => 'in_progress']);
    $archivedTask = Task::factory()->for($archived)->create(['status' => 'in_progress']);
    ProjectAssignment::create(['task_id' => $activeTask->id, 'member_id' => $this->member->id]);
    ProjectAssignment::create(['task_id' => $archivedTask->id, 'member_id' => $this->member->id]);

    $archived->archive();

    $this->get('/workspace/my')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('projects', fn ($projects) => collect($projects)->pluck('id')->all() === [$active->id])
            ->where('assignments', fn ($assignments) => collect($assignments)->count() === 1));
});

it('excludes archived-project snoozed assignments and todos from My Workspace', function () {
    $archived = Project::factory()->create();
    $task = Task::factory()->for($archived)->create(['status' => 'in_progress']);

    ProjectAssignment::create([
        'task_id' => $task->id,
        'member_id' => $this->member->id,
        'snoozed_until' => now()->addWeek(),
    ]);

    Subtask::create([
        'task_id' => $task->id,
        'user_id' => $this->user->id,
        'body' => 'Archived todo',
        'is_done' => false,
    ]);

    $archived->archive();

    $this->get('/workspace/my')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('snoozedCount', 0)
            ->where('openTodos', fn ($todos) => collect($todos)->count() === 0));
});

it('excludes archived projects from search results', function () {
    $archived = Project::factory()->create(['title' => 'Zebra Archived']);
    Task::factory()->for($archived)->create(['title' => 'Zebra Task']);
    $archived->archive();

    $response = $this->getJson('/workspace/search?q=Zebra')->json('data');

    expect(collect($response['projects'])->count())->toBe(0)
        ->and(collect($response['tasks'])->count())->toBe(0);
});
