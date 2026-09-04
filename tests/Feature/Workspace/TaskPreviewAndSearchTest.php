<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectAssignment;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;

it('returns everything the task peek needs from the preview endpoint', function () {
    $user = User::factory()->create(['name' => 'Kiran Timsina']);
    $this->actingAs($user);

    $task = Task::factory()->create(['priority' => 'high', 'status' => 'in_progress']);
    $member = Member::factory()->linkedTo($user)->create();
    $assignment = ProjectAssignment::create([
        'task_id' => $task->id,
        'member_id' => $member->id,
        'personal_progress' => 0,
    ]);
    Subtask::create(['task_id' => $task->id, 'user_id' => $user->id, 'body' => 'Call them', 'is_done' => false, 'position' => 1]);
    ProjectActivity::create([
        'task_id' => $task->id,
        'user_id' => $user->id,
        'subject_type' => Task::class,
        'subject_id' => $task->id,
        'action' => 'status_changed',
        'description' => 'changed status',
        'is_public' => true,
    ]);

    $response = $this->getJson("/workspace/tasks/{$task->id}/preview")->assertSuccessful();
    $data = $response->json('data');

    expect($data['task']['priority'])->toBe('high')
        ->and($data['task']['slug'])->toBe($task->slug)
        ->and($data['assignments'])->toHaveCount(1)
        ->and($data['assignments'][0]['id'])->toBe($assignment->id)
        ->and($data['assignments'][0]['member']['name'])->toBe('Kiran Timsina')
        ->and($data['subtasks'])->toHaveCount(1)
        ->and($data['subtasks'][0]['body'])->toBe('Call them')
        // The observers write their own rows for the task, assignment and
        // subtask created above, so assert on containment, not position.
        ->and(collect($data['activity'])->pluck('description'))->toContain('changed status')
        ->and(collect($data['team'])->pluck('name'))->toContain('Kiran Timsina')
        ->and($data)->toHaveKeys(['notes', 'contacts']);
});

it('includes matching projects in search results', function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']));
    Project::factory()->create(['title' => 'Budget Reform 2026', 'slug' => 'budget-reform']);
    Project::factory()->create(['title' => 'Roads', 'slug' => 'roads']);

    $data = $this->getJson('/workspace/search?q=budget')->assertSuccessful()->json('data');

    expect($data['projects'])->toHaveCount(1)
        ->and($data['projects'][0]['slug'])->toBe('budget-reform');
});

it('returns task ids and slugs on search hits so results can open the peek', function () {
    $this->actingAs(User::factory()->create());
    $task = Task::factory()->create(['title' => 'Hydropower licensing review']);

    $data = $this->getJson('/workspace/search?q=hydropower')->assertSuccessful()->json('data');

    expect($data['tasks'][0]['id'])->toBe($task->id)
        ->and($data['tasks'][0]['slug'])->toBe($task->slug);
});
