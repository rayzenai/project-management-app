<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Support\ActivityText;
use Illuminate\Support\Facades\DB;

/*
 * A task's activity reads in people's words: status labels, not keys;
 * dates, not timestamps; no internal numbers or empty brackets.
 */

/** @return list<string> */
function activityWords(Task $task): array
{
    return ProjectActivity::query()->where('task_id', $task->id)->orderBy('id')->pluck('description')->all();
}

it('words a change in labels, dates and "none", never keys or timestamps', function () {
    expect(ActivityText::status('not_started', 'in_progress'))->toBe('Status: Not Started → In Progress')
        ->and(ActivityText::status(null, 'done'))->toBe('Status: none → Done')
        // A key the workflow no longer has still reads as words.
        ->and(ActivityText::status('on_hold', 'failed'))->toBe('Status: On hold → Failed')
        ->and(ActivityText::change('Deadline', null, '2026-11-02 00:00:00'))->toBe('Deadline: none → Nov 2, 2026')
        // As a task's casts put it in JSON: UTC, so the office's date is the next day.
        ->and(ActivityText::change('Deadline', '2026-10-29T18:15:00.000000Z', null))->toBe('Deadline: Oct 30, 2026 → none')
        ->and(ActivityText::noteAdded('meeting', 'Agreed the scope'))->toBe('Note added (Meeting): "Agreed the scope"')
        ->and(ActivityText::noteAdded('general', 'Hello'))->toBe('Note added: "Hello"')
        ->and(ActivityText::change('Deadline', now()->setDate(2026, 11, 2), ''))->toBe('Deadline: Nov 2, 2026 → none')
        ->and(ActivityText::change('Status note', 'Waiting', str_repeat('x', 120)))->toBe('Status note: Waiting → '.str_repeat('x', 100).'…')
        ->and(ActivityText::priority('medium', 'urgent'))->toBe('Priority: Medium → Urgent')
        ->and(Task::labelForStatus('in_progress'))->toBe('In Progress');
});

it("writes a task's activity in people's words", function () {
    $task = Task::factory()->for(Project::factory())->create(['status' => 'not_started', 'deadline_at' => '2026-10-20']);

    $task->update(['status' => 'in_progress']);
    $task->update(['deadline_at' => '2026-11-02']);
    $task->update(['progress' => 100]);

    expect(activityWords($task))->toBe([
        'Created the task',
        'Status: Not Started → In Progress',
        'Deadline: Oct 20, 2026 → Nov 2, 2026',
        'Progress: 0% → 100%',
        'Marked complete',
    ]);
});

it("words an assignee's priority change by its label", function () {
    $task = Task::factory()->for(Project::factory())->create();
    $assignment = ProjectAssignment::create(['task_id' => $task->id, 'member_id' => Member::factory()->create(['name' => 'Sita'])->id, 'priority' => 'medium']);

    $assignment->update(['priority' => 'high']);

    expect(activityWords($task))->toContain('Assigned to Sita', 'Priority: Medium → High');
});

it('rewords the activity written before, from what each row recorded', function () {
    $task = Task::factory()->for(Project::factory())->create();
    ProjectActivity::query()->where('task_id', $task->id)->delete();
    $row = fn (string $subjectType, string $action, string $description, ?array $changes = null) => DB::table('project_activities')->insertGetId([
        'project_id' => $task->project_id, 'task_id' => $task->id, 'subject_type' => $subjectType, 'subject_id' => $task->id,
        'action' => $action, 'description' => $description, 'changes' => $changes === null ? null : json_encode($changes),
        'is_public' => false, 'created_at' => '2026-10-29 10:00:00', 'updated_at' => '2026-10-29 10:00:00',
    ]);
    $row('task', 'created', 'Plan #1 — "Ministry & Dept Capacity Assessment" created');
    $row('task', 'status_changed', 'Status: not_started → in_progress', ['from' => 'not_started', 'to' => 'in_progress']);
    $row('task', 'updated', 'Deadline updated: — → 2026-11-02 00:00:00', ['field' => 'deadline_at', 'from' => null, 'to' => '2026-11-02 00:00:00']);
    $row('task', 'completed', 'Plan marked complete', ['from' => 50, 'to' => 100]);
    $row('task', 'reopened', 'Plan reopened', ['from' => 100, 'to' => 50]);
    $row('project-assignment', 'created', 'Assigned to Avishek Shrestha ()');
    $row('project-assignment', 'created', 'Assigned to Ram (reviewer)');
    $row('project-assignment', 'updated', 'Priority: medium → high', ['from' => 'medium', 'to' => 'high']);
    $row('project-note', 'created', 'Note added: meeting — "Test note"');
    $row('task', 'updated', 'Deadline updated: 2026-10-29T18:15:00.000000Z → 2026-11-06 00:00:00', ['field' => 'deadline_at', 'from' => '2026-10-29T18:15:00.000000Z', 'to' => '2026-11-06 00:00:00']);
    // Recorded nothing to rebuild from: left as it is.
    $row('task', 'status_changed', 'Status: something else');

    (require base_path('database/migrations/2026_10_31_190000_reword_task_activity.php'))->up();

    expect(activityWords($task))->toBe([
        'Created the task',
        'Status: Not Started → In Progress',
        'Deadline: none → Nov 2, 2026',
        'Marked complete',
        'Reopened',
        'Assigned to Avishek Shrestha',
        'Assigned to Ram (reviewer)',
        'Priority: Medium → High',
        'Note added (Meeting): "Test note"',
        'Deadline: Oct 30, 2026 → Nov 6, 2026',
        'Status: something else',
    ])->and(DB::table('project_activities')->where('task_id', $task->id)->pluck('created_at')->unique()->values()->all())->toBe(['2026-10-29 10:00:00']);
});
