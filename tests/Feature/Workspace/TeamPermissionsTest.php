<?php

use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
});

it('forbids a non-super-admin from creating a team', function () {
    $this->actingAs(User::factory()->create());

    $this->post('/workspace/teams', ['name' => 'Sneaky'])->assertForbidden();

    expect(Team::query()->where('name', 'Sneaky')->exists())->toBeFalse();
});

it('forbids a non-super-admin from deleting a team', function () {
    $this->actingAs(User::factory()->create());
    $team = Team::factory()->create();

    $this->delete("/workspace/teams/{$team->id}")->assertForbidden();

    expect(Team::query()->find($team->id))->not->toBeNull();
});

it('allows a super-admin to create a team', function () {
    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']));

    $this->post('/workspace/teams', ['name' => 'Field Ops'])->assertRedirect();

    expect(Team::query()->where('name', 'Field Ops')->exists())->toBeTrue();
});

it('forbids a non-super-admin from updating a team', function () {
    $this->actingAs(User::factory()->create());
    $team = Team::factory()->create();

    $this->patch("/workspace/teams/{$team->id}", ['name' => 'Hacked'])->assertForbidden();

    expect($team->fresh()->name)->not->toBe('Hacked');
});
