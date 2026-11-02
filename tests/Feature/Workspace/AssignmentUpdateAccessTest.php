<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->project = Project::factory()->public()->create();
    $this->task = Task::factory()->create(['project_id' => $this->project->id]);
    $this->owner = User::factory()->withMember()->create();
    $this->assignment = ProjectAssignment::create([
        'task_id' => $this->task->id,
        'member_id' => WorkspaceAccess::memberFor($this->owner)->id,
    ]);
    $this->teammate = User::factory()->withMember()->create();
});

function personalPayloads(): array
{
    return [
        'is_focused' => ['is_focused' => true],
        'snoozed_until' => ['snoozed_until' => '2030-01-01'],
        'personal_due_at' => ['personal_due_at' => '2030-01-01'],
        'personal_progress' => ['personal_progress' => 40],
        'personal_status_note' => ['personal_status_note' => 'waiting'],
    ];
}

it('lets the assignee star and snooze their own assignment on the web', function () {
    $this->actingAs($this->owner);

    $this->patch("/workspace/assignments/{$this->assignment->id}", ['is_focused' => true])->assertRedirect();
    $this->patch("/workspace/assignments/{$this->assignment->id}", ['snoozed_until' => '2030-01-01'])->assertRedirect();

    $fresh = $this->assignment->fresh();
    expect($fresh->is_focused)->toBeTrue()->and($fresh->snoozed_until)->not->toBeNull();
});

it('refuses a teammate who can view the project changing someone else\'s personal fields on the web', function (string $field, array $payload) {
    $this->actingAs($this->teammate);

    $this->patch("/workspace/assignments/{$this->assignment->id}", $payload)->assertForbidden();

    expect($this->assignment->fresh()->is_focused)->toBeFalse()
        ->and($this->assignment->fresh()->snoozed_until)->toBeNull()
        ->and($this->assignment->fresh()->personal_due_at)->toBeNull();
})->with(fn () => collect(personalPayloads())->map(fn ($p, $k) => [$k, $p])->all());

it('still lets a teammate who can view the project change role and priority', function () {
    $this->actingAs($this->teammate);

    $this->patch("/workspace/assignments/{$this->assignment->id}", ['priority' => 'high'])->assertRedirect();

    expect($this->assignment->fresh()->priority)->toBe('high');
});

it('refuses a mixed payload carrying a personal field for someone else', function () {
    $this->actingAs($this->teammate);

    $this->patch("/workspace/assignments/{$this->assignment->id}", ['priority' => 'high', 'is_focused' => true])->assertForbidden();

    expect($this->assignment->fresh()->priority)->not->toBe('high');
});

it('refuses someone who cannot view the project even for priority', function () {
    $other = Organization::factory()->create();
    $outsider = User::factory()->create();
    Member::factory()->linkedTo($outsider)->create(['organization_id' => $other->id]);
    $this->actingAs($outsider);

    $this->patch("/workspace/assignments/{$this->assignment->id}", ['priority' => 'high'])->assertForbidden();

    $this->actingAs(User::factory()->create())
        ->patch("/workspace/assignments/{$this->assignment->id}", ['priority' => 'high'])->assertForbidden();
});

it('refuses the assignee once they are deactivated', function () {
    WorkspaceAccess::memberFor($this->owner)->update(['is_active' => false]);
    WorkspaceAccess::flush();
    $this->actingAs($this->owner);

    $this->patch("/workspace/assignments/{$this->assignment->id}", ['is_focused' => true])->assertRedirect();

    expect($this->assignment->fresh()->is_focused)->toBeFalse();
});

it('does not let a super-admin change someone else\'s star', function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']));

    $this->patch("/workspace/assignments/{$this->assignment->id}", ['is_focused' => true])->assertForbidden();
});

it('behaves the same on the API', function () {
    Sanctum::actingAs($this->owner, ['*']);
    $this->patchJson("/api/v1/workspace/assignments/{$this->assignment->id}", ['is_focused' => true])->assertOk();
    expect($this->assignment->fresh()->is_focused)->toBeTrue();

    Sanctum::actingAs($this->teammate, ['*']);
    $this->patchJson("/api/v1/workspace/assignments/{$this->assignment->id}", ['personal_due_at' => '2030-01-01'])->assertForbidden();
    $this->patchJson("/api/v1/workspace/assignments/{$this->assignment->id}", ['priority' => 'low'])->assertOk();

    $other = Organization::factory()->create();
    $outsider = User::factory()->create();
    Member::factory()->linkedTo($outsider)->create(['organization_id' => $other->id]);
    Sanctum::actingAs($outsider, ['*']);
    $this->patchJson("/api/v1/workspace/assignments/{$this->assignment->id}", ['priority' => 'high'])->assertForbidden();
});

it('refuses a deactivated assignee on the API', function () {
    WorkspaceAccess::memberFor($this->owner)->update(['is_active' => false]);
    WorkspaceAccess::flush();
    Sanctum::actingAs($this->owner, ['*']);

    $this->patchJson("/api/v1/workspace/assignments/{$this->assignment->id}", ['is_focused' => true])->assertUnauthorized();
});
