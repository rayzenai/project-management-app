<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Task statuses
    |--------------------------------------------------------------------------
    |
    | The ordered workflow a task moves through. Board columns, status chips,
    | and dashboards all render from this single definition; `is_complete`
    | marks the statuses that count a task as finished everywhere (progress
    | percentages, the My Workspace triage, the one-click complete checkbox).
    | `is_closed` marks the statuses where work has stopped, finished or not:
    | a closed task is never overdue, never on anyone's to-do list and never
    | reminded about. `is_started` marks the statuses that mean work began
    | (the work reports count a task as started when it enters one). Edit
    | here to change the workflow.
    |
    */

    'statuses' => [
        'not_started' => ['label' => 'Not Started', 'color' => '#9AA2B3', 'is_complete' => false, 'is_closed' => false],
        'unclear' => ['label' => 'Unclear', 'color' => '#9AA2B3', 'is_complete' => false, 'is_closed' => false],
        'in_progress' => ['label' => 'In Progress', 'color' => '#E5A33A', 'is_complete' => false, 'is_closed' => false, 'is_started' => true],
        'done' => ['label' => 'Done', 'color' => '#3EC98A', 'is_complete' => true, 'is_closed' => true],
        'failed' => ['label' => 'Failed', 'color' => '#F2655A', 'is_complete' => false, 'is_closed' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | One-click complete status
    |--------------------------------------------------------------------------
    |
    | The status applied when a task is completed via a checkbox (as opposed
    | to an explicit status pick). Must be a key of `statuses` above.
    |
    */

    'complete_status' => 'done',

    /*
    |--------------------------------------------------------------------------
    | Super-admins
    |--------------------------------------------------------------------------
    |
    | Emails of users who hold the `manage-workspace` ability — they create and
    | delete teams, manage every member, and archive any project. AppServiceProvider
    | registers the `manage-workspace` Gate from this list. Comma-separated via
    | the PM_SUPER_ADMINS env var.
    |
    */

    'super_admins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PM_SUPER_ADMINS', '')),
    ))),

    /*
    | Password used by WorkspaceSuperadminSeeder when provisioning the seeded
    | super-admin login on a fresh database.
    */

    'super_admin_default_password' => env('PM_SUPER_ADMIN_PASSWORD', 'password'),

    /*
    |--------------------------------------------------------------------------
    | Trash TTL (days)
    |--------------------------------------------------------------------------
    |
    | How long a soft-deleted workspace row is retained before the daily
    | `workspace:prune-trashed` command force-deletes it. Defaults to 30 days.
    |
    */

    'trash_ttl_days' => (int) env('PM_TRASH_TTL_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Deadline reminders
    |--------------------------------------------------------------------------
    |
    | Drives the daily reminder command. `reminder_lead_days` lists how many
    | days before a deadline to send a heads-up (an array so multiple lead
    | times are possible). `overdue_repeat_days` re-notifies overdue tasks
    | every N days. `run_at` is the scheduled time of the daily run.
    |
    */

    'reminders' => [
        'reminder_lead_days' => [2],
        'overdue_repeat_days' => 3,
        'run_at' => env('PM_REMINDERS_RUN_AT', '08:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Git forge
    |--------------------------------------------------------------------------
    |
    | The forge address the Organizations page fills in when a super-admin
    | turns git on for an organization: SDOC's forge in production. A local
    | forge (forgejo/local.sh) answers at http://127.0.0.1:3300.
    |
    */

    'git' => [
        'default_forge_url' => env('PM_GIT_FORGE_URL', 'https://git.sdoc.gov.np'),
    ],

];
