<?php

use App\Models\Member;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
});

function apiSuperAdmin(): User
{
    $admin = User::factory()->create(['email' => 'boss@example.com']);
    Sanctum::actingAs($admin, ['*']);

    return $admin;
}

function apiLeaderOf(Team $team): User
{
    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create();
    $team->members()->attach($member->id, ['role' => 'leader']);
    Sanctum::actingAs($user, ['*']);

    return $user;
}

it('lets a leader manage the roster of their own team', function () {
    $team = Team::factory()->create();
    apiLeaderOf($team);
    $member = Member::factory()->create();

    $this->postJson("/api/v1/workspace/teams/{$team->id}/members", ['member_id' => $member->id])
        ->assertCreated();

    expect($team->fresh()->members()->whereKey($member->id)->exists())->toBeTrue();

    $this->deleteJson("/api/v1/workspace/teams/{$team->id}/members/{$member->id}")
        ->assertOk();

    expect($team->fresh()->members()->whereKey($member->id)->exists())->toBeFalse();
});

it('forbids a leader from managing the roster of a team they do not lead', function () {
    $own = Team::factory()->create();
    apiLeaderOf($own);
    $foreign = Team::factory()->create();
    $member = Member::factory()->create();

    $this->postJson("/api/v1/workspace/teams/{$foreign->id}/members", ['member_id' => $member->id])
        ->assertForbidden();

    expect($foreign->fresh()->members()->whereKey($member->id)->exists())->toBeFalse();
});

it('forbids a leader from choosing leaders (the office admin does)', function () {
    $team = Team::factory()->create();
    apiLeaderOf($team);
    $member = Member::factory()->create();
    $team->members()->attach($member->id);

    $this->patchJson("/api/v1/workspace/teams/{$team->id}/members/{$member->id}", ['role' => 'leader'])
        ->assertForbidden();

    expect($team->fresh()->leaders()->whereKey($member->id)->exists())->toBeFalse();
});

it('lets the office admin of the team\'s organization set roles', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    Member::factory()->linkedTo($admin)->create(['is_office_admin' => true, 'organization_id' => $team->organization_id]);
    Sanctum::actingAs($admin, ['*']);
    $member = Member::factory()->create();
    $team->members()->attach($member->id);

    $this->patchJson("/api/v1/workspace/teams/{$team->id}/members/{$member->id}", ['role' => 'leader'])
        ->assertOk();

    expect($team->fresh()->leaders()->whereKey($member->id)->exists())->toBeTrue();
});

it('forbids a leader from changing the role of a super-admin member (privilege-escalation block)', function () {
    $team = Team::factory()->create();
    apiLeaderOf($team);

    $bossUser = User::factory()->create(['email' => 'boss@example.com']);
    $bossMember = Member::factory()->linkedTo($bossUser)->create();
    $team->members()->attach($bossMember->id);

    $this->patchJson("/api/v1/workspace/teams/{$team->id}/members/{$bossMember->id}", ['role' => 'leader'])
        ->assertForbidden();

    expect($team->fresh()->leaders()->whereKey($bossMember->id)->exists())->toBeFalse();
});
