<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectNote;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    $this->user = User::factory()->create(['email' => 'boss@example.com']);
});

it('surfaces the user task notes in the shared taskNotes prop with task context', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);
    $note = ProjectNote::create([
        'task_id' => $task->id,
        'user_id' => $this->user->id,
        'type' => 'general',
        'body' => 'Reviewed the plan with the team',
    ]);

    $this->actingAs($this->user)
        ->get('/workspace')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('taskNotes', fn (Collection $notes): bool => $notes->contains(
                fn (array $n): bool => $n['id'] === $note->id
                    && $n['body'] === 'Reviewed the plan with the team'
                    && $n['task']['slug'] === $task->slug
                    && $n['task']['project']['slug'] === $project->slug
            )));
});

it('scopes shared taskNotes to the authenticated author', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    $otherUser = User::factory()->create();
    $ownNote = ProjectNote::create([
        'task_id' => $task->id,
        'user_id' => $this->user->id,
        'type' => 'general',
        'body' => 'Mine',
    ]);
    $otherNote = ProjectNote::create([
        'task_id' => $task->id,
        'user_id' => $otherUser->id,
        'type' => 'general',
        'body' => 'Not mine',
    ]);

    $this->actingAs($this->user)
        ->get('/workspace')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('taskNotes', function (Collection $notes) use ($ownNote, $otherNote): bool {
                $ids = $notes->pluck('id');

                return $ids->contains($ownNote->id) && ! $ids->contains($otherNote->id);
            }));
});

it('surfaces task notes on tasks assigned to me even when authored by someone else', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    $myMember = Member::factory()->linkedTo($this->user)->create();
    ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $myMember->id]);

    $otherUser = User::factory()->create();
    $note = ProjectNote::create([
        'task_id' => $task->id,
        'user_id' => $otherUser->id,
        'type' => 'general',
        'body' => 'Authored by a teammate on my assigned task',
    ]);

    $this->actingAs($this->user)
        ->get('/workspace')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('taskNotes', fn (Collection $notes): bool => $notes->contains(
                fn (array $n): bool => $n['id'] === $note->id
            )));
});

it('excludes task notes on tasks not assigned to me and not authored by me', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    $otherUser = User::factory()->create();
    $note = ProjectNote::create([
        'task_id' => $task->id,
        'user_id' => $otherUser->id,
        'type' => 'general',
        'body' => 'Not mine, not my task',
    ]);

    $this->actingAs($this->user)
        ->get('/workspace')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('taskNotes', fn (Collection $notes): bool => ! $notes->pluck('id')->contains($note->id)));
});
