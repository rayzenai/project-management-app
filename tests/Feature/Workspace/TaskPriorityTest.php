<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('defaults new tasks to medium priority', function () {
    $task = Task::factory()->create();

    expect($task->fresh()->priority)->toBe('medium');
});

it('updates task priority through the task endpoint', function () {
    $this->actingAs(User::factory()->create());
    $task = Task::factory()->create(['status' => 'in_progress']);

    $this->patch("/workspace/projects/{$task->project->slug}/tasks/{$task->slug}", [
        'priority' => 'urgent',
    ])->assertRedirect();

    expect($task->fresh()->priority)->toBe('urgent');
});

it('rejects invalid priorities', function () {
    $this->actingAs(User::factory()->create());
    $task = Task::factory()->create();

    $this->from('/workspace')
        ->patch("/workspace/projects/{$task->project->slug}/tasks/{$task->slug}", [
            'priority' => 'mega',
        ])->assertSessionHasErrors('priority');
});

it('exposes priority on project page task payloads', function () {
    $this->actingAs(User::factory()->create());
    $project = Project::factory()->public()->create();
    Task::factory()->create(['project_id' => $project->id, 'priority' => 'high']);

    $this->get("/workspace/projects/{$project->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tasks.0.priority', 'high'));
});

it('sets task priority from quick add', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $project = Project::factory()->public()->create();

    $this->post('/workspace/quick-add', [
        'project_id' => $project->id,
        'title' => 'Urgent ministry call',
        'assignee_member_ids' => [Member::factory()->create()->id],
        'priority' => 'urgent',
    ])->assertRedirect();

    expect(Task::query()->where('title', 'Urgent ministry call')->first()->priority)->toBe('urgent');
});
