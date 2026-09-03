<?php

use Illuminate\Support\Facades\Schema;

it('has the task_reminder_logs guard table and reminder config', function () {
    expect(Schema::hasColumns('task_reminder_logs', ['task_id', 'window', 'reference_date', 'sent_at']))->toBeTrue()
        ->and(config('project-management.reminders.reminder_lead_days'))->toBe([2])
        ->and(config('project-management.reminders.overdue_repeat_days'))->toBe(3);
});
