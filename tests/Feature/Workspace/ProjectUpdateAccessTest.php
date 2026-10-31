<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

beforeEach(fn () => config(['project-management.super_admins' => ['boss@example.com']]));

it('lets the project\'s manager edit its details but never choose its manager', function () {
    $managerUser = User::factory()->create();
    $manager = Member::factory()->linkedTo($managerUser)->create();
    $project = Project::factory()->create(['title' => 'Before']);
    $project->ensureTeam()->members()->attach($manager->id, ['role' => 'leader']);
    $friend = Member::factory()->create(['organization_id' => $project->organization_id]);

    $this->actingAs($managerUser)->patch("/workspace/projects/{$project->slug}", [
        'title' => 'After',
        'manager_id' => $friend->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $project = $project->fresh();

    expect($project->title)->toBe('After')
        ->and($project->manager()?->id)->toBe($manager->id)
        ->and($project->team()->members()->whereKey($friend->id)->exists())->toBeFalse();
});

it('forbids updating a project the user cannot manage', function () {
    $leaderUser = User::factory()->create();
    $leader = Member::factory()->linkedTo($leaderUser)->create();
    Team::factory()->create()->members()->attach($leader->id, ['role' => 'leader']);

    $project = Project::factory()->create();
    $project->teams()->attach(Team::factory()->create()->id);

    $this->actingAs($leaderUser)->patch("/workspace/projects/{$project->slug}", ['title' => 'X'])
        ->assertForbidden();
});

it('lets the office admin hand a project to a new manager, keeping the old one on the team', function () {
    $adminUser = User::factory()->create();
    $project = Project::factory()->create();
    Member::factory()->linkedTo($adminUser)->create(['organization_id' => $project->organization_id, 'is_office_admin' => true]);
    $team = $project->ensureTeam();
    $old = Member::factory()->create(['organization_id' => $project->organization_id]);
    $team->members()->attach($old->id, ['role' => 'leader']);
    $new = Member::factory()->create(['organization_id' => $project->organization_id]);
    $outsider = Member::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $this->actingAs($adminUser)->from("/workspace/projects/{$project->slug}")
        ->patch("/workspace/projects/{$project->slug}", ['manager_id' => $outsider->id])
        ->assertSessionHasErrors('manager_id');

    $this->patch("/workspace/projects/{$project->slug}", ['manager_id' => $new->id])
        ->assertSessionHasNoErrors();

    expect($project->fresh()->manager()?->id)->toBe($new->id)
        ->and($team->leaders()->pluck('members.id')->all())->toBe([$new->id])
        ->and($team->members()->whereKey($old->id)->exists())->toBeTrue();
});

it('makes someone the manager through the team role too, and lets the office admin make them a member again', function () {
    $adminUser = User::factory()->create();
    $project = Project::factory()->create();
    Member::factory()->linkedTo($adminUser)->create(['organization_id' => $project->organization_id, 'is_office_admin' => true]);
    $team = $project->ensureTeam();
    $old = Member::factory()->create(['organization_id' => $project->organization_id]);
    $new = Member::factory()->create(['organization_id' => $project->organization_id]);
    $team->members()->attach([$old->id => ['role' => 'leader'], $new->id => ['role' => 'member']]);
    $this->actingAs($adminUser)->from("/workspace/projects/{$project->slug}");

    $this->patch("/workspace/teams/{$team->id}/members/{$new->id}", ['role' => 'leader'])->assertSessionHasNoErrors();

    expect($team->leaders()->pluck('members.id')->all())->toBe([$new->id]);

    $this->patch("/workspace/teams/{$team->id}/members/{$new->id}", ['role' => 'member'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash.message', "{$new->name} no longer manages {$team->name}.");

    expect($team->leaders()->count())->toBe(0)
        ->and($team->members()->pluck('members.id')->sort()->values()->all())->toBe(collect([$old->id, $new->id])->sort()->values()->all());
});

it('lets a super-admin set is_public on update', function () {
    $adminUser = User::factory()->create(['email' => 'boss@example.com']);
    Member::factory()->linkedTo($adminUser)->create();
    $project = Project::factory()->create(['is_public' => false]);

    $this->actingAs($adminUser)->patch("/workspace/projects/{$project->slug}", ['is_public' => true])
        ->assertRedirect();

    expect($project->fresh()->is_public)->toBeTrue();
});

it('does not let a leader toggle is_public', function () {
    $leaderUser = User::factory()->create();
    $leader = Member::factory()->linkedTo($leaderUser)->create();
    $team = Team::factory()->create();
    $team->members()->attach($leader->id, ['role' => 'leader']);
    $project = Project::factory()->create(['is_public' => false]);
    $project->teams()->attach($team->id);

    $this->actingAs($leaderUser)->patch("/workspace/projects/{$project->slug}", ['is_public' => true])
        ->assertRedirect();

    expect($project->fresh()->is_public)->toBeFalse();
});
