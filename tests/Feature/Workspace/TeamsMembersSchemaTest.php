<?php

use Illuminate\Support\Facades\Schema;

it('creates teams, members, and pivot tables', function () {
    expect(Schema::hasTable('teams'))->toBeTrue()
        ->and(Schema::hasTable('members'))->toBeTrue()
        ->and(Schema::hasTable('member_team'))->toBeTrue()
        ->and(Schema::hasTable('project_team'))->toBeTrue();
});

it('keys project_assignments by member, not user', function () {
    expect(Schema::hasColumn('project_assignments', 'member_id'))->toBeTrue()
        ->and(Schema::hasColumn('project_assignments', 'user_id'))->toBeFalse();
});

it('no longer adds coordinator columns to users', function () {
    expect(Schema::hasColumn('users', 'coordinator_categories'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'coordinator_role'))->toBeFalse();
});

it('bakes priority into the tasks create migration', function () {
    expect(Schema::hasColumn('tasks', 'priority'))->toBeTrue();
});

it('adds the role pivot column and the project archived_at column', function () {
    expect(Schema::hasColumn('member_team', 'role'))->toBeTrue()
        ->and(Schema::hasColumn('projects', 'archived_at'))->toBeTrue();
});
