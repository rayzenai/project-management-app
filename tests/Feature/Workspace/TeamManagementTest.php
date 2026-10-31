<?php

use App\Mail\LoginDetails;
use App\Models\Member;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Database\Factories\OrganizationFactory;
use Illuminate\Support\Facades\Mail;
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

it('creates a member with a working login', function () {
    $team = Team::factory()->create();

    $this->post('/workspace/members', [
        'organization_id' => OrganizationFactory::defaultId(),
        'name' => 'Ram Karki',
        'email' => 'ram@example.com',
        'password' => 'secret-pass-123',
        'team_ids' => [$team->id],
    ])->assertRedirect();

    $member = Member::query()->where('name', 'Ram Karki')->firstOrFail();
    $user = User::query()->where('email', 'ram@example.com')->firstOrFail();

    expect($member->user_id)->toBe($user->id)
        ->and($member->is_active)->toBeTrue()
        ->and($member->teams()->pluck('teams.id')->all())->toBe([$team->id])
        ->and(auth()->validate(['email' => 'ram@example.com', 'password' => 'secret-pass-123']))->toBeTrue();
});

it('emails a new member the email and password they sign in with', function () {
    Mail::fake();

    $this->post('/workspace/members', ['name' => 'Ram Karki', 'email' => 'ram@example.com', 'password' => 'Kp7m-Qx4r-Tz9a', 'organization_id' => OrganizationFactory::defaultId()])
        ->assertRedirect()
        ->assertSessionHas('workspace_flash.message', 'Member added. Login details emailed.');

    Mail::assertSent(LoginDetails::class, fn (LoginDetails $mail): bool => $mail->hasTo('ram@example.com')
        && $mail->password === 'Kp7m-Qx4r-Tz9a');
});

it('requires an email', function () {
    $this->from('/workspace/team')
        ->post('/workspace/members', ['name' => 'Ram Karki'])
        ->assertSessionHasErrors('email');

    expect(Member::query()->where('name', 'Ram Karki')->exists())->toBeFalse();
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

it('narrows who can be assigned to the project\'s team, which keeps the project\'s name', function () {
    $project = Project::factory()->create(['title' => 'Old Title']);
    $inTeam = Member::factory()->create(['name' => 'Ana']);
    $project->ensureTeam()->members()->attach($inTeam->id);
    Member::factory()->create(['name' => 'Outsider']);

    expect(Member::assignableFor($project)->pluck('name')->all())->toBe(['Ana']);

    $this->patch("/workspace/projects/{$project->slug}", ['title' => 'New Title'])->assertRedirect();

    expect($project->fresh()->team()->name)->toBe('New Title');
});

it('exposes the project\'s team and manager on the project page', function () {
    $project = Project::factory()->create();
    $manager = Member::factory()->create(['name' => 'Meera Manager']);
    $project->ensureTeam()->members()->attach($manager->id, ['role' => 'leader']);

    $this->get("/workspace/projects/{$project->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('project.team.id', $project->team()->id)
            ->where('project.manager.name', 'Meera Manager')
            ->where('team.members.0.is_manager', true));
});
