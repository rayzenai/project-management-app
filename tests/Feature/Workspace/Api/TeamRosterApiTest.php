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

it('forbids a plain member from creating a team', function () {
    Sanctum::actingAs(User::factory()->create(), ['*']);

    $this->postJson('/api/v1/workspace/teams', ['name' => 'Sneaky'])->assertForbidden();

    expect(Team::query()->where('name', 'Sneaky')->exists())->toBeFalse();
});

it('forbids a plain member from deleting a team', function () {
    Sanctum::actingAs(User::factory()->create(), ['*']);
    $team = Team::factory()->create();

    $this->deleteJson("/api/v1/workspace/teams/{$team->id}")->assertForbidden();

    expect(Team::query()->find($team->id))->not->toBeNull();
});

it('lets a super-admin create and delete a team', function () {
    apiSuperAdmin();

    $this->postJson('/api/v1/workspace/teams', ['name' => 'Field Ops'])
        ->assertCreated()
        ->assertJsonStructure(['message', 'data']);

    $team = Team::query()->where('name', 'Field Ops')->firstOrFail();

    $this->deleteJson("/api/v1/workspace/teams/{$team->id}")->assertOk();

    expect(Team::query()->find($team->id))->toBeNull();
});

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

it('forbids a non-super-admin leader from renaming their team', function () {
    $team = Team::factory()->create(['name' => 'Original']);
    apiLeaderOf($team);

    $this->patchJson("/api/v1/workspace/teams/{$team->id}", ['name' => 'Hacked'])
        ->assertForbidden();

    expect($team->fresh()->name)->toBe('Original');
});

it('lets a leader set roles within their team', function () {
    $team = Team::factory()->create();
    apiLeaderOf($team);
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
