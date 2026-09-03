<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;

it('assigns a member to a task', function () {
    $this->actingAs(User::factory()->create());
    $task = Task::factory()->create();
    $member = Member::factory()->create();

    $this->post("/workspace/tasks/{$task->id}/assignments", ['member_id' => $member->id])
        ->assertRedirect();

    expect($task->assignments()->count())->toBe(1)
        ->and($task->assignments()->first()->member_id)->toBe($member->id);
});

it('rejects assigning the same member twice', function () {
    $this->actingAs(User::factory()->create());
    $task = Task::factory()->create();
    $member = Member::factory()->create();
    ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $member->id]);

    $this->from('/workspace')
        ->post("/workspace/tasks/{$task->id}/assignments", ['member_id' => $member->id])
        ->assertRedirect('/workspace');

    expect($task->assignments()->count())->toBe(1);
});

it('rejects assigning a member outside the project teams scope', function () {
    $this->actingAs(User::factory()->create());
    $project = Project::factory()->public()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    $team = Team::factory()->create();
    $project->teams()->attach($team->id);

    $outsider = Member::factory()->create();

    $this->from('/workspace')
        ->post("/workspace/tasks/{$task->id}/assignments", ['member_id' => $outsider->id])
        ->assertSessionHasErrors('member_id');

    expect($task->assignments()->count())->toBe(0);
});

it('unassigns a member', function () {
    $this->actingAs(User::factory()->create());
    $task = Task::factory()->create();
    $member = Member::factory()->create();
    $assignment = ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $member->id]);

    $this->delete("/workspace/assignments/{$assignment->id}")->assertRedirect();

    expect($task->assignments()->count())->toBe(0);
});

it('quick-adds with explicit member ids', function () {
    $this->actingAs(User::factory()->create());
    $project = Project::factory()->public()->create();
    $member = Member::factory()->create();

    $this->post('/workspace/quick-add', [
        'project_id' => $project->id,
        'title' => 'Call the ministry',
        'assignee_member_ids' => [$member->id],
    ])->assertRedirect();

    $task = Task::query()->where('title', 'Call the ministry')->firstOrFail();

    expect($task->assignments()->pluck('member_id')->all())->toBe([$member->id]);
});

it('quick-add defaults the assignee to the acting user member', function () {
    $user = User::factory()->create(['name' => 'Kiran Timsina']);
    $this->actingAs($user);
    $project = Project::factory()->public()->create();

    $this->post('/workspace/quick-add', [
        'project_id' => $project->id,
        'title' => 'Solo task',
    ])->assertRedirect();

    $task = Task::query()->where('title', 'Solo task')->firstOrFail();
    $member = Member::query()->where('user_id', $user->id)->firstOrFail();

    expect($task->assignments()->pluck('member_id')->all())->toBe([$member->id]);
});

it('quick-add @name resolves an unlinked member within project scope', function () {
    $this->actingAs(User::factory()->create());
    $project = Project::factory()->public()->create();
    $team = Team::factory()->create();
    $project->teams()->attach($team->id);

    $sita = Member::factory()->create(['name' => 'Sita Sharma']);
    $team->members()->attach($sita->id);

    // Same name prefix outside the team must NOT win.
    Member::factory()->create(['name' => 'Sita Outside']);

    $this->post('/workspace/quick-add', [
        'project_id' => $project->id,
        'title' => 'Call NEA @sita',
    ])->assertRedirect();

    $task = Task::query()->where('title', 'Call NEA')->firstOrFail();

    expect($task->assignments()->pluck('member_id')->all())->toBe([$sita->id]);
});

it('rejects quick-add explicit member ids outside the project scope', function () {
    $this->actingAs(User::factory()->create());
    $project = Project::factory()->public()->create();
    $team = Team::factory()->create();
    $project->teams()->attach($team->id);

    $outsider = Member::factory()->create();

    $this->from('/workspace')
        ->post('/workspace/quick-add', [
            'project_id' => $project->id,
            'title' => 'Sneaky task',
            'assignee_member_ids' => [$outsider->id],
        ])->assertRedirect('/workspace');

    expect(Task::query()->where('title', 'Sneaky task')->exists())->toBeFalse();
});

it('scopes mine through the member link including coordinator categories', function () {
    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create([
        'coordinator_categories' => ['economy'],
    ]);

    $assigned = Task::factory()->create();
    ProjectAssignment::create(['task_id' => $assigned->id, 'member_id' => $member->id]);

    $coordinated = Task::factory()->create(['category' => 'economy']);
    Task::factory()->create(['category' => 'education']);

    $mine = Task::query()->mine($user)->pluck('tasks.id')->all();

    expect($mine)->toContain($assigned->id)
        ->and($mine)->toContain($coordinated->id)
        ->and($mine)->toHaveCount(2);
});

it('exposes the member object on assignment payloads', function () {
    $this->actingAs(User::factory()->create());
    $member = Member::factory()->create(['name' => 'Sita Sharma']);
    $task = Task::factory()->create();
    ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $member->id]);

    $data = $this->get("/workspace/tasks/{$task->id}/preview")->assertOk()->json('data');

    expect($data['assignments'][0]['member']['name'])->toBe('Sita Sharma')
        ->and($data['assignments'][0]['member_id'])->toBe($member->id);
});

it('shares members and the current member id in quickAddContext', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Member::factory()->create(['name' => 'Sita Sharma']);
    Member::factory()->inactive()->create(['name' => 'Gone Person']);

    $this->get('/workspace/my')
        ->assertInertia(fn ($page) => $page
            ->where('quickAddContext.currentMemberId', Member::query()->where('user_id', $user->id)->first()->id)
            ->where('quickAddContext.team', fn ($team) => collect($team)->pluck('name')->doesntContain('Gone Person')));
});
