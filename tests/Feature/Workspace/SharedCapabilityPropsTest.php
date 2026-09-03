<?php

use App\Models\Member;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
});

it('shares super-admin flag and empty led teams for a super-admin', function () {
    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']));

    $this->get('/workspace/team')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('isSuperAdmin', true)
            ->where('ledTeamIds', []));
});

it('shares led team ids for a leader', function () {
    $user = User::factory()->create();
    $leader = Member::factory()->linkedTo($user)->create();
    $team = Team::factory()->create();
    $team->members()->attach($leader->id, ['role' => 'leader']);

    $this->actingAs($user);

    $this->get('/workspace/team')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('isSuperAdmin', false)
            ->where('ledTeamIds', [$team->id])
            ->where('teams.0.leader_ids', [$leader->id]));
});
