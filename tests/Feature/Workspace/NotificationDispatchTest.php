<?php

use App\Models\Member;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskStatusChanged;
use Illuminate\Support\Facades\Notification;

it('notifies the assigned member (with a linked user)', function () {
    Notification::fake();
    $task = Task::factory()->create();
    $member = Member::factory()->withUser()->create();

    ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $member->id]);

    Notification::assertSentTo($member->user, TaskAssigned::class);
});

it('does not notify members without a linked user', function () {
    Notification::fake();
    $task = Task::factory()->create();
    $member = Member::factory()->create(['user_id' => null]);

    ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $member->id]);

    Notification::assertNothingSent();
});

it('notifies other assignees (not the actor) when status becomes failed', function () {
    $task = Task::factory()->create(['status' => 'in_progress']);
    $actor = Member::factory()->withUser()->create();
    $other = Member::factory()->withUser()->create();

    ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $actor->id]);
    ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $other->id]);

    Notification::fake();

    $this->actingAs($actor->user);

    $task->status = 'failed';
    $task->save();

    Notification::assertSentTo($other->user, TaskStatusChanged::class);
    Notification::assertNotSentTo($actor->user, TaskStatusChanged::class);
});

it('dispatches TaskStatusChanged when status becomes an in-scope status', function (string $status) {
    $task = Task::factory()->create(['status' => 'in_progress']);
    $assignee = Member::factory()->withUser()->create();

    ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $assignee->id]);

    Notification::fake();

    $task->status = $status;
    $task->save();

    Notification::assertSentTo($assignee->user, TaskStatusChanged::class);
})->with(['done', 'failed']);

it('dispatches nothing when status changes to an out-of-scope status', function (string $status) {
    $task = Task::factory()->create(['status' => 'not_started']);
    $assignee = Member::factory()->withUser()->create();

    ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $assignee->id]);

    Notification::fake();

    $task->status = $status;
    $task->save();

    Notification::assertNotSentTo($assignee->user, TaskStatusChanged::class);
    Notification::assertNothingSent();
})->with(['in_progress', 'unclear']);
