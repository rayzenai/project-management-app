<?php

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Models\ProjectAssignment;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;

/*
 * One action, one toast: a page that announces a result itself (the done
 * circle, My work's row menu and to-do check) sends X-Workspace-Quiet, and
 * the server's success flash stays out of the way. Failures still flash.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    $this->user = User::factory()->withMember()->create(['email' => 'boss@example.com']);
    $this->actingAs($this->user);
});

it('says only what changed when a task with a deadline is marked done', function () {
    $task = Task::factory()->create(['status' => 'not_started', 'deadline_at' => '2026-11-20']);

    $this->patch("/workspace/projects/{$task->project->slug}/tasks/{$task->slug}", ['status' => 'done'])
        ->assertRedirect()
        ->assertSessionHas('workspace_flash.message', 'Status: Not Started → Done');
});

it('still names a real change of date', function () {
    $task = Task::factory()->create(['deadline_at' => '2026-11-20']);

    $this->patch("/workspace/projects/{$task->project->slug}/tasks/{$task->slug}", ['deadline_at' => '2026-11-22'])
        ->assertSessionHas('workspace_flash.message', 'Due: 2026-11-20 → 2026-11-22');
});

it('flashes nothing when the done circle announces the change itself', function () {
    $task = Task::factory()->create(['status' => 'not_started']);

    $this->withHeaders(['X-Workspace-Quiet' => '1'])
        ->patch("/workspace/projects/{$task->project->slug}/tasks/{$task->slug}", ['status' => 'done'])
        ->assertRedirect()
        ->assertSessionMissing('workspace_flash');

    expect($task->fresh()->status)->toBe('done');
});

it('flashes nothing for a quiet change of your own assignment or to-do', function () {
    $task = Task::factory()->create();
    $assignment = ProjectAssignment::create(['task_id' => $task->id, 'member_id' => WorkspaceAccess::memberFor($this->user)?->id]);
    $subtask = Subtask::create(['task_id' => $task->id, 'user_id' => $this->user->id, 'body' => 'Call Dolakha', 'position' => 1]);

    $this->withHeaders(['X-Workspace-Quiet' => '1'])
        ->patch("/workspace/assignments/{$assignment->id}", ['is_focused' => true])
        ->assertSessionMissing('workspace_flash');
    $this->withHeaders(['X-Workspace-Quiet' => '1'])
        ->patch("/workspace/subtasks/{$subtask->id}", ['is_done' => true])
        ->assertSessionMissing('workspace_flash');

    expect($assignment->fresh()->is_focused)->toBeTrue()
        ->and($subtask->fresh()->is_done)->toBeTrue();
});

it('keeps the flash for the same changes made without the header', function () {
    $task = Task::factory()->create();
    $assignment = ProjectAssignment::create(['task_id' => $task->id, 'member_id' => WorkspaceAccess::memberFor($this->user)?->id]);
    $subtask = Subtask::create(['task_id' => $task->id, 'user_id' => $this->user->id, 'body' => 'Call Dolakha', 'position' => 1]);

    $this->patch("/workspace/assignments/{$assignment->id}", ['is_focused' => true])
        ->assertSessionHas('workspace_flash.message', 'Assignment updated.');
    $this->patch("/workspace/subtasks/{$subtask->id}", ['is_done' => true])
        ->assertSessionHas('workspace_flash.message', 'Marked done.');
});

it('still flashes a failure when asked to be quiet', function () {
    $controller = new class
    {
        use RedirectsWithServiceResult;

        public function respond(ServiceResult $result): RedirectResponse
        {
            return $this->redirectWithResult($result);
        }
    };

    request()->headers->set('X-Workspace-Quiet', '1');
    $controller->respond(ServiceResult::failure('Could not save.', 422));

    expect(session('workspace_flash'))->toMatchArray(['success' => false, 'message' => 'Could not save.']);
});
