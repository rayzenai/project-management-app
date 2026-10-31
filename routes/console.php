<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('workspace:prune-trashed')->daily()->withoutOverlapping();

Schedule::command('workspace:send-deadline-reminders')
    ->dailyAt((string) config('project-management.reminders.run_at', '08:00'))
    ->withoutOverlapping();

// Spent and revoked OAuth tokens from forge sign-ins (Passport).
Schedule::command('passport:purge')->daily()->withoutOverlapping();

// Forgejo never retries a webhook: fill the gaps, put back what forge owners
// changed, and keep forge owners and members in step with the tracker.
Schedule::command('git:reconcile')->dailyAt('01:30')->withoutOverlapping();

// Each linked repository's forge collaborators, and main's rule, in step with the tracker.
Schedule::command('git:sync-access')->everyTenMinutes()->withoutOverlapping(15);
// Count each new commit's code, comment and blank lines from its diff.
Schedule::command('git:commit-stats')->everyFiveMinutes()->withoutOverlapping(30);
// Read recent commits' checks (test results) a webhook may have missed.
Schedule::command('git:commit-checks')->everyTwoMinutes()->withoutOverlapping(10);

// Each command runs in a process of its own whose output, and with
// LOG_CHANNEL=stderr its log, would be thrown away. In a container whose
// first process is the scheduler (production's), that process's stderr is
// the container's log; anywhere else nothing changes.
if (is_writable('/proc/1/fd/2')) {
    foreach (Schedule::events() as $event) {
        $event->appendOutputTo('/proc/1/fd/2');
    }
}
