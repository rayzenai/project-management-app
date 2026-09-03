<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']));
});

it('renders the team page with teams, members, and linkable users', function () {
    $team = Team::factory()->create(['name' => 'Field Ops']);
    $member = Member::factory()->create(['name' => 'Sita Sharma']);
    $team->members()->attach($member->id);

    $this->get('/workspace/team')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Team/Index')
            ->where('teams.0.name', 'Field Ops')
            ->where('teams.0.member_ids', [$member->id])
            ->where('members.0.name', 'Sita Sharma'));
});

it('creates a team with members', function () {
    $member = Member::factory()->create();

    $this->post('/workspace/teams', ['name' => 'Field Ops', 'member_ids' => [$member->id]])
        ->assertRedirect();

    $team = Team::query()->where('name', 'Field Ops')->firstOrFail();

    expect($team->slug)->toBe('field-ops')
        ->and($team->members()->pluck('members.id')->all())->toBe([$member->id]);
});

it('rejects a team without a name', function () {
    $this->from('/workspace/team')
        ->post('/workspace/teams', ['name' => ''])
        ->assertSessionHasErrors('name');
});

it('renames a team and syncs its members', function () {
    $team = Team::factory()->create(['name' => 'Old']);
    $keep = Member::factory()->create();
    $drop = Member::factory()->create();
    $team->members()->attach([$keep->id, $drop->id]);

    $this->patch("/workspace/teams/{$team->id}", ['name' => 'New Name', 'member_ids' => [$keep->id]])
        ->assertRedirect();

    expect($team->fresh()->name)->toBe('New Name')
        ->and($team->members()->pluck('members.id')->all())->toBe([$keep->id]);
});

it('deletes a team without deleting its members', function () {
    $team = Team::factory()->create();
    $member = Member::factory()->create();
    $team->members()->attach($member->id);

    $this->delete("/workspace/teams/{$team->id}")->assertRedirect();

    expect(Team::query()->find($team->id))->toBeNull()
        ->and(Member::query()->find($member->id))->not->toBeNull();
});

it('creates a member with a working login', function () {
    $team = Team::factory()->create();

    $this->post('/workspace/members', [
        'name' => 'Ram Karki',
        'email' => 'ram@example.com',
        'password' => 'secret-pass-123',
        'title' => 'Engineer',
        'team_ids' => [$team->id],
    ])->assertRedirect();

    $member = Member::query()->where('name', 'Ram Karki')->firstOrFail();
    $user = User::query()->where('email', 'ram@example.com')->firstOrFail();

    expect($member->user_id)->toBe($user->id)
        ->and($member->is_active)->toBeTrue()
        ->and($member->teams()->pluck('teams.id')->all())->toBe([$team->id])
        ->and(auth()->validate(['email' => 'ram@example.com', 'password' => 'secret-pass-123']))->toBeTrue();
});

it('creates a member without a login when no password is given', function () {
    $usersBefore = User::query()->count();

    $this->post('/workspace/members', ['name' => 'Ram Karki', 'title' => 'Engineer'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $member = Member::query()->where('name', 'Ram Karki')->firstOrFail();

    expect($member->user_id)->toBeNull()
        ->and(User::query()->count())->toBe($usersBefore);
});

it('requires an email when a password is given', function () {
    $this->from('/workspace/team')
        ->post('/workspace/members', ['name' => 'Ram Karki', 'password' => 'secret-pass-123'])
        ->assertSessionHasErrors('email');
});

it('rejects an email already used by another login', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->from('/workspace/team')
        ->post('/workspace/members', [
            'name' => 'Ram Karki',
            'email' => 'taken@example.com',
            'password' => 'secret-pass-123',
        ])->assertSessionHasErrors('email');
});

it('syncs name, email, and password changes to the login', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $member = Member::factory()->linkedTo($user)->create();

    $this->patch("/workspace/members/{$member->id}", [
        'name' => 'Renamed Person',
        'email' => 'new@example.com',
        'password' => 'brand-new-pass-1',
    ])->assertRedirect();

    expect($member->fresh()->email)->toBe('new@example.com')
        ->and($user->fresh()->name)->toBe('Renamed Person')
        ->and($user->fresh()->email)->toBe('new@example.com')
        ->and(auth()->validate(['email' => 'new@example.com', 'password' => 'brand-new-pass-1']))->toBeTrue();
});

it('upgrades a member without a login to a login user', function () {
    $member = Member::factory()->create(['user_id' => null, 'email' => null]);

    $this->patch("/workspace/members/{$member->id}", [
        'email' => 'late@example.com',
        'password' => 'finally-a-pass-1',
    ])->assertRedirect();

    expect($member->fresh()->user_id)->not->toBeNull()
        ->and(auth()->validate(['email' => 'late@example.com', 'password' => 'finally-a-pass-1']))->toBeTrue();
});

it('deactivates a member', function () {
    $member = Member::factory()->create();

    $this->patch("/workspace/members/{$member->id}", ['is_active' => false])->assertRedirect();

    expect($member->fresh()->is_active)->toBeFalse();
});

it('deletes a member and its login together', function () {
    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create();

    $this->delete("/workspace/members/{$member->id}")->assertRedirect();

    expect(Member::query()->find($member->id))->toBeNull()
        ->and(User::query()->find($user->id))->toBeNull();
});

it('syncs project teams and narrows the assignable set', function () {
    $project = Project::factory()->create();
    $team = Team::factory()->create();
    $inTeam = Member::factory()->create(['name' => 'Ana']);
    $team->members()->attach($inTeam->id);
    Member::factory()->create(['name' => 'Outsider']);

    $this->patch("/workspace/projects/{$project->slug}", ['team_ids' => [$team->id]])
        ->assertRedirect();

    expect($project->teams()->pluck('teams.id')->all())->toBe([$team->id])
        ->and(Member::assignableFor($project)->pluck('name')->all())->toBe(['Ana']);

    $this->patch("/workspace/projects/{$project->slug}", ['team_ids' => []])->assertRedirect();

    expect($project->fresh()->teams()->count())->toBe(0);
});

it('exposes project team ids on the project page', function () {
    $project = Project::factory()->create();
    $team = Team::factory()->create();
    $project->teams()->attach($team->id);

    $this->get("/workspace/projects/{$project->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('project.team_ids', [$team->id])
            ->has('teams'));
});
