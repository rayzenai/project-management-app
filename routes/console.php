<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('workspace:prune-trashed')->daily()->withoutOverlapping();

Schedule::command('workspace:send-deadline-reminders')
    ->dailyAt((string) config('project-management.reminders.run_at', '08:00'))
    ->withoutOverlapping();

// Spent and revoked OAuth tokens from forge sign-ins (Passport).
Schedule::command('passport:purge')->daily()->withoutOverlapping();
