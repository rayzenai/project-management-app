<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
});

it('shows a project to a member of an attached team, hides others, shows public to all', function () {
    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create();
    $team = Team::factory()->create();
    $team->members()->attach($member->id, ['role' => 'member']);

    $mine = Project::factory()->create(['is_public' => false]);
    $mine->teams()->attach($team->id);

    $foreign = Project::factory()->create(['is_public' => false]);
    $foreign->teams()->attach(Team::factory()->create()->id);

    $public = Project::factory()->create(['is_public' => true]);

    $visible = Project::query()->visibleTo($user)->pluck('id');

    expect($visible)->toContain($mine->id)
        ->and($visible)->toContain($public->id)
        ->and($visible)->not->toContain($foreign->id);
});

it('shows every project to a super-admin', function () {
    $admin = User::factory()->create(['email' => 'boss@example.com']);
    $private = Project::factory()->create(['is_public' => false]);
    $private->teams()->attach(Team::factory()->create()->id);

    expect(Project::query()->visibleTo($admin)->pluck('id'))->toContain($private->id);
});

it('hides teamless private projects from non-admins', function () {
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();
    $orphan = Project::factory()->create(['is_public' => false]);

    expect(Project::query()->visibleTo($user)->pluck('id'))->not->toContain($orphan->id);
});

it('shows a guest (null user) nothing, not even public projects', function () {
    Project::factory()->create(['is_public' => true]);

    $private = Project::factory()->create(['is_public' => false]);
    $private->teams()->attach(Team::factory()->create()->id);

    expect(Project::query()->visibleTo(null)->pluck('id'))->toBeEmpty();
});
