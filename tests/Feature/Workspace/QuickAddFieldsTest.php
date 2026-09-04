<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    Member::factory()->linkedTo($this->user)->create();
    $this->project = Project::factory()->public()->create();
    $this->actingAs($this->user);
});

it('stores an explicit status and description', function () {
    $this->post('/workspace/quick-add', [
        'project_id' => $this->project->id,
        'title' => 'Write the brief',
        'status' => 'in_progress',
        'description' => 'Two pages, no annexes.',
    ])->assertRedirect();

    $task = Task::query()->where('title', 'Write the brief')->sole();

    expect($task->status)->toBe('in_progress')
        ->and($task->description)->toBe('Two pages, no annexes.');
});

it('keeps the long-standing default when no status is sent', function () {
    // API callers that predate the status field must not change behaviour.
    $this->post('/workspace/quick-add', [
        'project_id' => $this->project->id,
        'title' => 'No status given',
    ])->assertRedirect();

    expect(Task::query()->where('title', 'No status given')->sole()->status)->toBe('unclear');
});

it('rejects a status outside the configured workflow', function () {
    $this->post('/workspace/quick-add', [
        'project_id' => $this->project->id,
        'title' => 'Bad status',
        'status' => 'not_a_status',
    ])->assertSessionHasErrors('status');

    expect(Task::query()->where('title', 'Bad status')->exists())->toBeFalse();
});

it('answers with a message rather than a 404 when the project is archived', function () {
    $this->project->forceFill(['archived_at' => now()])->save();

    $this->from('/workspace')
        ->post('/workspace/quick-add', [
            'project_id' => $this->project->id,
            'title' => 'Into the void',
        ])
        // findOrFail used to blow the whole modal away with a 404 page.
        ->assertRedirect('/workspace')
        ->assertSessionHasErrors();

    expect(Task::query()->where('title', 'Into the void')->exists())->toBeFalse();
});

it('surfaces a title that is nothing but tokens as an error', function () {
    $this->from('/workspace')
        ->post('/workspace/quick-add', [
            'project_id' => $this->project->id,
            'title' => "#{$this->project->slug} !high",
        ])
        ->assertRedirect('/workspace')
        ->assertSessionHasErrors();

    expect(Task::query()->count())->toBe(0);
});

it('carries the status through the api surface too', function () {
    $token = $this->user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/workspace/quick-add', [
            'project_id' => $this->project->id,
            'title' => 'Via the api',
            'status' => 'done',
            'description' => 'Closed on arrival.',
        ])->assertSuccessful();

    $task = Task::query()->where('title', 'Via the api')->sole();

    expect($task->status)->toBe('done')
        ->and($task->description)->toBe('Closed on arrival.')
        ->and($task->completed_at)->not->toBeNull();
});

it('lands on the new task project board when the dialog asks for it', function () {
    $this->from('/workspace')
        ->post('/workspace/quick-add', [
            'project_id' => $this->project->id,
            'title' => 'Show me where this went',
            'redirect_to_project' => true,
        ])
        ->assertRedirect(route('workspace.projects.show', [
            'project' => $this->project,
            'view' => 'board',
        ]));
});

it('follows the #token project rather than the picker when redirecting', function () {
    // The picker can say one project while a #token sends the task to another;
    // the redirect has to follow the task, not the form field.
    $other = Project::factory()->public()->create(['slug' => 'far-away']);

    $this->post('/workspace/quick-add', [
        'project_id' => $this->project->id,
        'title' => 'Routed by token #far-away',
        'redirect_to_project' => true,
    ])->assertRedirect(route('workspace.projects.show', [
        'project' => $other,
        'view' => 'board',
    ]));

    expect(Task::query()->where('project_id', $other->id)->count())->toBe(1);
});

it('stays put when the dialog does not ask to be redirected', function () {
    // The inline quick-add bar never sends the flag: it must not navigate away.
    $this->from('/workspace/my')
        ->post('/workspace/quick-add', [
            'project_id' => $this->project->id,
            'title' => 'Added from the inline bar',
        ])
        ->assertRedirect('/workspace/my');
});
