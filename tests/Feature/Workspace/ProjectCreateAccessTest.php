<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Database\Factories\OrganizationFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->admin = User::factory()->create();
    Member::factory()->linkedTo($this->admin)->create(['organization_id' => OrganizationFactory::defaultId(), 'is_office_admin' => true]);
    $this->manager = Member::factory()->create(['organization_id' => OrganizationFactory::defaultId(), 'name' => 'Meera Manager']);
});

it('creates a project with its own team, led by the manager', function () {
    $this->actingAs($this->admin)->post('/workspace/projects', [
        'title' => 'Roadmap',
        'manager_id' => $this->manager->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $project = Project::query()->where('title', 'Roadmap')->firstOrFail();
    $team = $project->team();

    expect($team)->not->toBeNull()
        ->and($team->name)->toBe('Roadmap')
        ->and($team->organization_id)->toBe($project->organization_id)
        ->and($team->members()->pluck('members.id')->all())->toBe([$this->manager->id])
        ->and($project->manager()?->id)->toBe($this->manager->id)
        ->and($project->is_public)->toBeFalse();
});

it('does the same over the API', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->postJson('/api/v1/workspace/projects', ['title' => 'Over the wire', 'manager_id' => $this->manager->id])
        ->assertCreated();

    expect(Project::query()->where('title', 'Over the wire')->firstOrFail()->manager()?->id)->toBe($this->manager->id);
});

it('requires an active manager from the project\'s organization', function () {
    $inactive = Member::factory()->create(['organization_id' => OrganizationFactory::defaultId(), 'is_active' => false]);
    $elsewhere = Member::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $this->actingAs($this->admin)->from('/workspace/projects');

    $this->post('/workspace/projects', ['title' => 'No manager'])->assertSessionHasErrors('manager_id');
    $this->post('/workspace/projects', ['title' => 'Asleep', 'manager_id' => $inactive->id])->assertSessionHasErrors('manager_id');
    $this->post('/workspace/projects', ['title' => 'Abroad', 'manager_id' => $elsewhere->id])->assertSessionHasErrors('manager_id');

    expect(Project::query()->count())->toBe(0)
        ->and(Team::query()->count())->toBe(0);
});

it('leaves creating projects to office admins and super-admins', function () {
    $managerUser = User::factory()->create();
    $existing = Member::factory()->linkedTo($managerUser)->create(['organization_id' => OrganizationFactory::defaultId()]);
    Team::factory()->create()->members()->attach($existing->id, ['role' => 'leader']);
    $plain = User::factory()->create();
    Member::factory()->linkedTo($plain)->create(['organization_id' => OrganizationFactory::defaultId()]);

    foreach ([$managerUser, $plain] as $user) {
        $this->actingAs($user)->post('/workspace/projects', ['title' => 'Nope', 'manager_id' => $this->manager->id])->assertForbidden();
    }

    expect(Project::query()->where('title', 'Nope')->exists())->toBeFalse();
});

it('lets the office admin publish a project to their organization', function () {
    $this->actingAs($this->admin)->post('/workspace/projects', [
        'title' => 'Notice', 'manager_id' => $this->manager->id, 'is_public' => true,
    ])->assertRedirect();

    expect(Project::query()->where('title', 'Notice')->firstOrFail()->is_public)->toBeTrue();
});

it('lets a super-admin create a project in a chosen organization', function () {
    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']))->post('/workspace/projects', [
        'title' => 'Announce', 'is_public' => true, 'organization_id' => OrganizationFactory::defaultId(), 'manager_id' => $this->manager->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $project = Project::query()->where('title', 'Announce')->firstOrFail();

    expect($project->is_public)->toBeTrue()
        ->and($project->manager()?->id)->toBe($this->manager->id);
});
