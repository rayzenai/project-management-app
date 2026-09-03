<?php

use Illuminate\Support\Facades\Schema;

it('adds deleted_at to every soft-deletable workspace table', function (string $table) {
    expect(Schema::hasColumn($table, 'deleted_at'))->toBeTrue();
})->with([
    'tasks', 'subtasks', 'project_notes', 'project_contacts',
    'project_assignments', 'workspace_notes', 'teams', 'members',
]);
