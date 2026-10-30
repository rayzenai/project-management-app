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

// Count each new commit's code, comment and blank lines from its diff.
Schedule::command('git:commit-stats')->everyFiveMinutes()->withoutOverlapping();
