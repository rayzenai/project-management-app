<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectNote;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkspaceNote;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    $this->admin = User::factory()->create(['email' => 'boss@example.com']);
    $this->actingAs($this->admin);
});

it('flashes an undo descriptor pointing at the team restore route', function () {
    $team = Team::factory()->create();

    $response = $this->delete("/workspace/teams/{$team->id}");

    $response->assertRedirect();
    $response->assertSessionHas('workspace_flash.undo.label', 'Undo');
    $response->assertSessionHas('workspace_flash.undo.url', route('workspace.teams.restore', $team));
});

it('flashes an undo descriptor pointing at the member restore route', function () {
    $member = Member::factory()->create();

    $response = $this->delete("/workspace/members/{$member->id}");

    $response->assertRedirect();
    $response->assertSessionHas('workspace_flash.undo.label', 'Undo');
    $response->assertSessionHas('workspace_flash.undo.url', route('workspace.members.restore', $member));
});

it('flashes an undo descriptor pointing at the nested task restore route', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    $response = $this->delete("/workspace/projects/{$project->slug}/tasks/{$task->slug}");

    $response->assertRedirect();
    $response->assertSessionHas('workspace_flash.undo.label', 'Undo');
    $response->assertSessionHas('workspace_flash.undo.url', route('workspace.tasks.restore', [$project, $task]));
});

it('flashes an undo descriptor pointing at the subtask restore route', function () {
    $task = Task::factory()->create();
    $subtask = Subtask::create(['task_id' => $task->id, 'user_id' => $this->admin->id, 'body' => 'Do it', 'position' => 1]);

    $response = $this->delete("/workspace/subtasks/{$subtask->id}");

    $response->assertRedirect();
    $response->assertSessionHas('workspace_flash.undo.label', 'Undo');
    $response->assertSessionHas('workspace_flash.undo.url', route('workspace.subtasks.restore', $subtask));
});

it('flashes an undo descriptor pointing at the assignment restore route', function () {
    $task = Task::factory()->create();
    $member = Member::factory()->create();
    $assignment = ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $member->id]);

    $response = $this->delete("/workspace/assignments/{$assignment->id}");

    $response->assertRedirect();
    $response->assertSessionHas('workspace_flash.undo.label', 'Undo');
    $response->assertSessionHas('workspace_flash.undo.url', route('workspace.assignments.restore', $assignment));
});

it('flashes an undo descriptor pointing at the note restore route', function () {
    $task = Task::factory()->create();
    $note = ProjectNote::create(['task_id' => $task->id, 'user_id' => $this->admin->id, 'type' => 'general', 'body' => 'A note']);

    $response = $this->delete("/workspace/notes/{$note->id}");

    $response->assertRedirect();
    $response->assertSessionHas('workspace_flash.undo.label', 'Undo');
    $response->assertSessionHas('workspace_flash.undo.url', route('workspace.notes.restore', $note));
});

it('flashes an undo descriptor pointing at the workspace note restore route', function () {
    $note = WorkspaceNote::create(['user_id' => $this->admin->id, 'body' => 'Sticky', 'color' => 'amber']);

    $response = $this->delete("/workspace/my-notes/{$note->id}");

    $response->assertRedirect();
    $response->assertSessionHas('workspace_flash.undo.label', 'Undo');
    $response->assertSessionHas('workspace_flash.undo.url', route('workspace.my-notes.restore', $note));
});
