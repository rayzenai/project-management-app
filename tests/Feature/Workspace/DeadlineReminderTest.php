<?php

use App\Models\Member;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Notifications\TaskDeadlineDue;
use Illuminate\Support\Facades\Notification;

function assignedTask(array $attrs): Task
{
    $task = Task::factory()->create($attrs);
    $member = Member::factory()->withUser()->create();
    ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $member->id]);

    // Re-arm the notification fake so the TaskAssigned dispatched by the
    // ProjectAssignmentObserver during setup is not counted against assertions
    // about what the reminder command itself sends.
    Notification::fake();

    return $task->refresh();
}

it('notifies assignees of a task due today, once', function () {
    Notification::fake();
    $this->travelTo(now()->setTime(8, 0));
    assignedTask(['deadline_at' => today(), 'status' => 'in_progress']);

    $this->artisan('workspace:send-deadline-reminders')->assertSuccessful();
    $this->artisan('workspace:send-deadline-reminders')->assertSuccessful(); // 2nd run same day

    Notification::assertSentTimes(TaskDeadlineDue::class, 1);
});

it('sends a heads-up exactly lead-days before, not otherwise', function () {
    Notification::fake();
    assignedTask(['deadline_at' => today()->addDays(2), 'status' => 'in_progress']);
    $this->artisan('workspace:send-deadline-reminders')->assertSuccessful();
    Notification::assertSentTimes(TaskDeadlineDue::class, 1);
});

it('does not send a heads-up on a non-lead day', function () {
    Notification::fake();
    assignedTask(['deadline_at' => today()->addDays(5), 'status' => 'in_progress']);
    $this->artisan('workspace:send-deadline-reminders')->assertSuccessful();
    Notification::assertNothingSent();
});

it('never reminds completed tasks', function () {
    Notification::fake();
    assignedTask(['deadline_at' => today(), 'status' => 'done']);
    $this->artisan('workspace:send-deadline-reminders')->assertSuccessful();
    Notification::assertNothingSent();
});

it('re-notifies overdue tasks on the configured cadence', function () {
    Notification::fake();
    assignedTask(['deadline_at' => today()->subDays(1), 'status' => 'in_progress']);

    $this->artisan('workspace:send-deadline-reminders'); // overdue day 1
    $this->travelTo(now()->addDay());
    $this->artisan('workspace:send-deadline-reminders'); // within repeat window — no resend
    Notification::assertSentTimes(TaskDeadlineDue::class, 1);

    $this->travelTo(now()->addDays(3)); // past overdue_repeat_days
    $this->artisan('workspace:send-deadline-reminders');
    Notification::assertSentTimes(TaskDeadlineDue::class, 2);
});

it('pretend mode sends nothing and writes no logs', function () {
    Notification::fake();
    assignedTask(['deadline_at' => today(), 'status' => 'in_progress']);
    $this->artisan('workspace:send-deadline-reminders --pretend')->assertSuccessful();
    Notification::assertNothingSent();
    expect(DB::table('task_reminder_logs')->count())->toBe(0);
});

it('pretend reflects already-sent reminders (reports nothing after a real send)', function () {
    Notification::fake();
    assignedTask(['deadline_at' => today(), 'status' => 'in_progress']);

    $this->artisan('workspace:send-deadline-reminders')->assertSuccessful();

    $this->artisan('workspace:send-deadline-reminders --pretend')
        ->expectsOutputToContain('[pretend] Reminders dispatched: 0.')
        ->assertSuccessful();
});
