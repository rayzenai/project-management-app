<?php

use Illuminate\Console\Scheduling\Schedule;

it('schedules the deadline reminder command daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($e) => $e->command)
        ->filter(fn ($c) => str_contains((string) $c, 'workspace:send-deadline-reminders'));

    expect($events)->not->toBeEmpty();
});
