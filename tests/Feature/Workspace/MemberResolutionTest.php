<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

it('creates a member once per user and copies name and email', function () {
    $user = User::factory()->create(['name' => 'Kiran Timsina', 'email' => 'kiran@example.com']);

    $member = Member::forUser($user);
    $again = Member::forUser($user);

    expect($member->id)->toBe($again->id)
        ->and(Member::query()->count())->toBe(1)
        ->and($member->name)->toBe('Kiran Timsina')
        ->and($member->email)->toBe('kiran@example.com')
        ->and($member->user_id)->toBe($user->id);
});

it('scopes assignable members to the project teams union', function () {
    $project = Project::factory()->create();
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();
    $offTeam = Team::factory()->create();

    $inA = Member::factory()->create(['name' => 'Ana']);
    $inB = Member::factory()->create(['name' => 'Bal']);
    $inBoth = Member::factory()->create(['name' => 'Cam']);
    $outside = Member::factory()->create(['name' => 'Dev']);

    $teamA->members()->attach([$inA->id, $inBoth->id]);
    $teamB->members()->attach([$inB->id, $inBoth->id]);
    $offTeam->members()->attach($outside->id);

    $project->teams()->attach([$teamA->id, $teamB->id]);

    $names = Member::assignableFor($project)->pluck('name')->all();

    expect($names)->toBe(['Ana', 'Bal', 'Cam']);
});

it('falls back to all active members when the project has no teams', function () {
    $project = Project::factory()->create();
    Member::factory()->create(['name' => 'Ana']);
    Member::factory()->create(['name' => 'Bal']);

    expect(Member::assignableFor($project)->pluck('name')->all())->toBe(['Ana', 'Bal']);
});

it('excludes inactive members from the assignable set in both modes', function () {
    $project = Project::factory()->create();
    $team = Team::factory()->create();

    $active = Member::factory()->create(['name' => 'Ana']);
    $inactive = Member::factory()->inactive()->create(['name' => 'Bal']);
    $team->members()->attach([$active->id, $inactive->id]);

    expect(Member::assignableFor($project)->pluck('name')->all())->toBe(['Ana']);

    $project->teams()->attach($team->id);

    expect(Member::assignableFor($project)->pluck('name')->all())->toBe(['Ana']);
});

it('derives a unique team slug from the name', function () {
    $team = Team::create(['name' => 'Field Ops']);

    expect($team->slug)->toBe('field-ops');
});
