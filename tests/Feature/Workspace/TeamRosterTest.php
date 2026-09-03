<?php

use App\Models\Member;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    $this->team = Team::factory()->create();
    $user = User::factory()->create();
    $leader = Member::factory()->linkedTo($user)->create();
    $this->team->members()->attach($leader->id, ['role' => 'leader']);
    $this->actingAs($user);
});

it('lets a leader attach an existing member to their team', function () {
    $member = Member::factory()->create();

    $this->post("/workspace/teams/{$this->team->id}/members", ['member_id' => $member->id])
        ->assertRedirect();

    expect($this->team->fresh()->members()->whereKey($member->id)->exists())->toBeTrue();
});

it('lets a leader create and provision a new member on their team', function () {
    $this->post("/workspace/teams/{$this->team->id}/members", [
        'name' => 'Fresh Recruit',
        'email' => 'recruit@example.com',
        'password' => 'recruit-pass-1',
    ])->assertRedirect();

    $member = Member::query()->where('name', 'Fresh Recruit')->firstOrFail();

    expect($member->user_id)->not->toBeNull()
        ->and($this->team->fresh()->members()->whereKey($member->id)->exists())->toBeTrue();
});

it('lets a leader detach a member from their team without deleting them', function () {
    $member = Member::factory()->create();
    $this->team->members()->attach($member->id);

    $this->delete("/workspace/teams/{$this->team->id}/members/{$member->id}")->assertRedirect();

    expect($this->team->fresh()->members()->whereKey($member->id)->exists())->toBeFalse()
        ->and(Member::query()->find($member->id))->not->toBeNull();
});

it('lets a leader promote and demote a team member', function () {
    $member = Member::factory()->create();
    $this->team->members()->attach($member->id);

    $this->patch("/workspace/teams/{$this->team->id}/members/{$member->id}", ['role' => 'leader'])
        ->assertRedirect();

    expect($this->team->fresh()->leaders()->whereKey($member->id)->exists())->toBeTrue();

    $this->patch("/workspace/teams/{$this->team->id}/members/{$member->id}", ['role' => 'member'])
        ->assertRedirect();

    expect($this->team->fresh()->leaders()->whereKey($member->id)->exists())->toBeFalse();
});

it('forbids managing the roster of a team you do not lead', function () {
    $foreign = Team::factory()->create();
    $member = Member::factory()->create();

    $this->post("/workspace/teams/{$foreign->id}/members", ['member_id' => $member->id])
        ->assertForbidden();
});

it('forbids a leader from changing the team role of a super-admin member', function () {
    $bossUser = User::factory()->create(['email' => 'boss@example.com']);
    $bossMember = Member::factory()->linkedTo($bossUser)->create();
    $this->team->members()->attach($bossMember->id);

    $this->patch("/workspace/teams/{$this->team->id}/members/{$bossMember->id}", ['role' => 'leader'])
        ->assertForbidden();

    expect($this->team->fresh()->leaders()->whereKey($bossMember->id)->exists())->toBeFalse();
});

it('forbids a leader from attaching an existing login-bearing member they have no authority over', function () {
    $outsiderUser = User::factory()->create();
    $outsider = Member::factory()->linkedTo($outsiderUser)->create();

    $this->post("/workspace/teams/{$this->team->id}/members", ['member_id' => $outsider->id])
        ->assertForbidden();

    expect($this->team->fresh()->members()->whereKey($outsider->id)->exists())->toBeFalse();
});
