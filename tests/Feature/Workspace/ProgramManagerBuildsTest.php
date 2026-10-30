<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

/*
 * A program manager (a ministry's Joint Secretary) builds their division's
 * work: they create projects in their own programs, choosing each one's
 * manager, and later replace the manager of any project in them. Nothing
 * else: no project outside their programs, no removing a manager, no key,
 * publishing, cross-organization team or moving between programs.
 *
 * Organization O: an organizational admin; program P managed by the joint
 * secretary with project A (managed by the under secretary, with an
 * officer); program Q managed by someone else with project B. Organization
 * X has its own program and person.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->org = Organization::factory()->create(['name' => 'Ministry']);
    $this->otherOrg = Organization::factory()->create(['name' => 'Elsewhere']);

    $this->adminUser = User::factory()->create();
    Member::factory()->linkedTo($this->adminUser)->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);

    $this->js = User::factory()->create();
    $this->jsMember = Member::factory()->linkedTo($this->js)->create(['organization_id' => $this->org->id, 'title' => 'Joint Secretary']);
    $this->programP = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->jsMember->id, 'name' => 'Revenue']);

    $this->usUser = User::factory()->create();
    $this->us = Member::factory()->linkedTo($this->usUser)->create(['organization_id' => $this->org->id, 'title' => 'Under Secretary']);
    $this->officerUser = User::factory()->create();
    $this->officer = Member::factory()->linkedTo($this->officerUser)->create(['organization_id' => $this->org->id, 'title' => 'Officer']);
    $this->projectA = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $this->programP->id, 'title' => 'Tax Portal']);
    $this->teamA = $this->projectA->ensureTeam();
    $this->teamA->members()->attach($this->us->id, ['role' => 'leader']);
    $this->teamA->members()->attach($this->officer->id, ['role' => 'member']);

    $this->qManager = Member::factory()->create(['organization_id' => $this->org->id]);
    $this->programQ = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->qManager->id, 'name' => 'Customs']);
    $this->projectB = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $this->programQ->id, 'title' => 'Border Posts']);
    $this->teamB = $this->projectB->ensureTeam();
    $this->managerB = Member::factory()->create(['organization_id' => $this->org->id]);
    $this->officerB = Member::factory()->create(['organization_id' => $this->org->id]);
    $this->teamB->members()->attach($this->managerB->id, ['role' => 'leader']);
    $this->teamB->members()->attach($this->officerB->id, ['role' => 'member']);

    $this->outsider = Member::factory()->create(['organization_id' => $this->otherOrg->id, 'name' => 'Visiting Expert']);
    $this->programX = Program::factory()->create(['organization_id' => $this->otherOrg->id, 'manager_id' => $this->outsider->id, 'name' => 'Foreign']);

    // Someone of O on no team yet, to be chosen as a manager.
    $this->colleague = Member::factory()->create(['organization_id' => $this->org->id, 'name' => 'Sita Section Officer']);
});

it('lets a program manager create a project in their program and choose its manager', function () {
    expect(WorkspaceAccess::canCreateProject($this->js))->toBeTrue()
        ->and(WorkspaceAccess::canCreateProjectInProgram($this->js, $this->programP))->toBeTrue()
        ->and(WorkspaceAccess::canCreateProjectInProgram($this->js, $this->programQ))->toBeFalse()
        ->and(WorkspaceAccess::canCreateProjectInProgram($this->js, $this->programX))->toBeFalse()
        ->and(WorkspaceAccess::canCreateProjectInProgram($this->adminUser, $this->programQ))->toBeTrue()
        ->and(WorkspaceAccess::canCreateProjectInProgram($this->adminUser, $this->programX))->toBeFalse();

    $this->actingAs($this->js)->post('/workspace/projects', [
        'title' => 'Revenue Dashboard',
        'program_id' => $this->programP->id,
        'manager_id' => $this->colleague->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $project = Project::query()->where('title', 'Revenue Dashboard')->firstOrFail();

    expect($project->organization_id)->toBe($this->org->id)
        ->and($project->program_id)->toBe($this->programP->id)
        ->and($project->team()->leaders()->pluck('members.id')->all())->toBe([$this->colleague->id]);

    // The API twin; managing it themselves is the form's default.
    $this->actingAs($this->js, 'sanctum')->postJson('/api/v1/workspace/projects', [
        'title' => 'Revenue Audit',
        'program_id' => $this->programP->id,
        'manager_id' => $this->jsMember->id,
    ])->assertCreated()->assertJsonPath('data.program.slug', $this->programP->slug);

    $own = Project::query()->where('title', 'Revenue Audit')->firstOrFail();

    expect($own->organization_id)->toBe($this->org->id)
        ->and($own->team()->leaders()->pluck('members.id')->all())->toBe([$this->jsMember->id]);
});

it('gives a program manager the create form for their programs only, managed by their organization', function () {
    $this->actingAs($this->js)->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canCreate', true)
            ->where('canPublish', false)
            ->where('programRequired', true)
            ->where('defaultManagerId', $this->jsMember->id)
            ->where('programs', fn (Collection $programs): bool => $programs->pluck('id')->all() === [$this->programP->id]
                && $programs->every(fn (array $program): bool => $program['can_create_project'] === true))
            ->where('managerCandidates', fn (Collection $people): bool => $people->pluck('id')->contains($this->colleague->id)
                && $people->pluck('id')->contains($this->jsMember->id)
                && ! $people->pluck('id')->contains($this->outsider->id)
                && $people->every(fn (array $person): bool => $person['organization_id'] === $this->org->id)));

    // An organizational admin's form is as before: any program of theirs, none required.
    $this->actingAs($this->adminUser)->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canCreate', true)
            ->where('programRequired', false)
            ->where('defaultManagerId', null)
            ->where('programs', fn (Collection $programs): bool => $programs->count() === 2
                && $programs->every(fn (array $program): bool => $program['can_create_project'] === true)));
});

it('drops key, publishing and cross-organization from a program manager', function () {
    $this->actingAs($this->js)->post('/workspace/projects', [
        'title' => 'Water Portal',
        'program_id' => $this->programP->id,
        'manager_id' => $this->colleague->id,
        'key' => 'ZZZ',
        'is_public' => true,
        'is_cross_organization' => true,
        'organization_id' => $this->otherOrg->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $project = Project::query()->where('title', 'Water Portal')->firstOrFail();

    expect($project->organization_id)->toBe($this->org->id)
        ->and($project->key)->not->toBe('ZZZ')
        ->and($project->is_public)->toBeFalse()
        ->and($project->team()->is_cross_organization)->toBeFalse();

    // Nor on a project of their program, nor moving it out of the program.
    $key = $this->projectA->fresh()->key;

    $this->actingAs($this->js)->patch("/workspace/projects/{$this->projectA->slug}", [
        'key' => 'YYY',
        'is_public' => true,
        'is_cross_organization' => true,
        'program_id' => $this->programQ->id,
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->js)->patch("/workspace/projects/{$this->projectA->slug}", ['program_id' => null])
        ->assertSessionHasNoErrors();

    $a = $this->projectA->fresh();

    expect($a->key)->toBe($key)
        ->and($a->is_public)->toBeFalse()
        ->and($a->program_id)->toBe($this->programP->id)
        ->and($this->teamA->fresh()->is_cross_organization)->toBeFalse();
});

it('refuses a program manager a project outside their programs', function () {
    $before = Project::query()->count();

    foreach ([$this->programQ->id, $this->programX->id, null] as $programId) {
        $this->actingAs($this->js)->post('/workspace/projects', array_filter([
            'title' => 'Hijack',
            'program_id' => $programId,
            'manager_id' => $this->colleague->id,
        ]))->assertSessionHasErrors(['program_id' => 'Choose one of your programs.']);

        $this->actingAs($this->js, 'sanctum')->postJson('/api/v1/workspace/projects', array_filter([
            'title' => 'Hijack',
            'program_id' => $programId,
            'manager_id' => $this->colleague->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['program_id' => 'Choose one of your programs.']);
    }

    // In their program, but managed by someone they may not choose.
    $inactive = Member::factory()->inactive()->create(['organization_id' => $this->org->id]);

    foreach ([$this->outsider->id, $inactive->id] as $managerId) {
        $this->actingAs($this->js)->post('/workspace/projects', [
            'title' => 'Hijack',
            'program_id' => $this->programP->id,
            'manager_id' => $managerId,
        ])->assertSessionHasErrors('manager_id');
    }

    expect(Project::query()->count())->toBe($before)
        ->and(Project::query()->where('title', 'Hijack')->exists())->toBeFalse();
});

it('lets a program manager replace the manager of a project in their program', function () {
    expect(WorkspaceAccess::canAppointProjectManager($this->js, $this->teamA))->toBeTrue()
        ->and(WorkspaceAccess::canAppointProjectManager($this->js, $this->teamB))->toBeFalse()
        ->and(WorkspaceAccess::canEditTeam($this->js, $this->teamA))->toBeFalse();

    $this->actingAs($this->js)->patch("/workspace/projects/{$this->projectA->slug}", ['manager_id' => $this->colleague->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->teamA->leaders()->pluck('members.id')->all())->toBe([$this->colleague->id])
        ->and($this->teamA->members()->whereKey($this->us->id)->exists())->toBeTrue();

    // The People tab's "Make manager" goes through the team role route.
    $this->actingAs($this->js)->patch("/workspace/teams/{$this->teamA->id}/members/{$this->officer->id}", ['role' => 'leader'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->actingAs($this->js, 'sanctum')->patchJson("/api/v1/workspace/teams/{$this->teamA->id}/members/{$this->us->id}", ['role' => 'leader'])
        ->assertOk();

    expect($this->teamA->leaders()->pluck('members.id')->all())->toBe([$this->us->id])
        ->and($this->teamA->members()->whereKey($this->officer->id)->exists())->toBeTrue()
        ->and($this->teamA->members()->whereKey($this->colleague->id)->exists())->toBeTrue();

    // Never someone who cannot join the team.
    $this->actingAs($this->js)->patch("/workspace/projects/{$this->projectA->slug}", ['manager_id' => $this->outsider->id])
        ->assertSessionHasErrors('manager_id');
    $this->actingAs($this->js)->patch("/workspace/teams/{$this->teamA->id}/members/{$this->outsider->id}", ['role' => 'leader'])
        ->assertSessionHasErrors('role');

    // On B, outside their programs, both are refused.
    $this->actingAs($this->js)->patch("/workspace/projects/{$this->projectB->slug}", ['manager_id' => $this->colleague->id])
        ->assertForbidden();
    $this->actingAs($this->js)->patch("/workspace/teams/{$this->teamB->id}/members/{$this->officerB->id}", ['role' => 'leader'])
        ->assertForbidden();
    $this->actingAs($this->js, 'sanctum')->patchJson("/api/v1/workspace/teams/{$this->teamB->id}/members/{$this->officerB->id}", ['role' => 'leader'])
        ->assertForbidden();

    // Nor a project of another organization pointed at their program by bad data.
    $stray = Project::factory()->create(['organization_id' => $this->otherOrg->id, 'program_id' => $this->programP->id]);
    $strayTeam = $stray->ensureTeam();

    expect(WorkspaceAccess::canAppointProjectManager($this->js, $strayTeam))->toBeFalse();

    $this->actingAs($this->js)->patch("/workspace/teams/{$strayTeam->id}/members/{$this->outsider->id}", ['role' => 'leader'])
        ->assertForbidden();

    expect($this->teamB->leaders()->pluck('members.id')->all())->toBe([$this->managerB->id])
        ->and($this->teamA->leaders()->pluck('members.id')->all())->toBe([$this->us->id])
        ->and($strayTeam->leaders()->count())->toBe(0);
});

it('shows a program manager the make-manager control on their program projects only', function () {
    $this->actingAs($this->js)->get("/workspace/projects/{$this->projectA->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('team.can_choose_manager', true)
            ->where('project.can_choose_manager', true)
            ->where('project.can_change_key', false)
            ->where('project.can_publish', false)
            ->where('programs', []));

    $this->actingAs($this->usUser)->get("/workspace/projects/{$this->projectA->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('team.can_choose_manager', false)
            ->where('project.can_choose_manager', false));
});

it('still never lets a program manager remove a manager', function () {
    $this->actingAs($this->js)->delete("/workspace/teams/{$this->teamA->id}/members/{$this->us->id}")
        ->assertForbidden();
    $this->actingAs($this->js, 'sanctum')->deleteJson("/api/v1/workspace/teams/{$this->teamA->id}/members/{$this->us->id}")
        ->assertForbidden();

    // Nor step them down without choosing someone else.
    $this->actingAs($this->js)->patch("/workspace/teams/{$this->teamA->id}/members/{$this->us->id}", ['role' => 'member'])
        ->assertSessionHasErrors('role');

    expect($this->teamA->leaders()->pluck('members.id')->all())->toBe([$this->us->id])
        ->and(WorkspaceAccess::canRemoveFromTeam($this->js, $this->teamA, $this->us))->toBeFalse();
});

it('takes the new powers away from a deactivated program manager', function () {
    $this->jsMember->update(['is_active' => false]);
    WorkspaceAccess::flush();

    expect(WorkspaceAccess::canCreateProject($this->js))->toBeFalse()
        ->and(WorkspaceAccess::canCreateProjectInProgram($this->js, $this->programP))->toBeFalse()
        ->and(WorkspaceAccess::canAppointProjectManager($this->js, $this->teamA))->toBeFalse();

    $this->actingAs($this->js)->post('/workspace/projects', [
        'title' => 'Late Project',
        'program_id' => $this->programP->id,
        'manager_id' => $this->colleague->id,
    ])->assertForbidden();
    $this->actingAs($this->js)->patch("/workspace/projects/{$this->projectA->slug}", ['manager_id' => $this->colleague->id])
        ->assertForbidden();
    $this->actingAs($this->js)->patch("/workspace/teams/{$this->teamA->id}/members/{$this->officer->id}", ['role' => 'leader'])
        ->assertForbidden();

    expect(Project::query()->where('title', 'Late Project')->exists())->toBeFalse()
        ->and($this->teamA->leaders()->pluck('members.id')->all())->toBe([$this->us->id]);
});

it('changes nothing for a project manager or member', function () {
    foreach ([$this->usUser, $this->officerUser] as $user) {
        expect(WorkspaceAccess::canCreateProject($user))->toBeFalse()
            ->and(WorkspaceAccess::canCreateProjectInProgram($user, $this->programP))->toBeFalse()
            ->and(WorkspaceAccess::canAppointProjectManager($user, $this->teamA))->toBeFalse();

        $this->actingAs($user)->post('/workspace/projects', [
            'title' => 'Not Mine',
            'program_id' => $this->programP->id,
            'manager_id' => $this->colleague->id,
        ])->assertForbidden();
        $this->actingAs($user)->patch("/workspace/teams/{$this->teamA->id}/members/{$this->colleague->id}", ['role' => 'leader'])
            ->assertForbidden();

        $this->actingAs($user)->get('/workspace/projects')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canCreate', false)
                ->where('programRequired', false)
                ->where('defaultManagerId', null)
                ->where('managerCandidates', []));
    }

    // Its manager edits the project; their manager_id is still dropped.
    $this->actingAs($this->usUser)->patch("/workspace/projects/{$this->projectA->slug}", ['manager_id' => $this->colleague->id])
        ->assertSessionHasNoErrors();

    expect(Project::query()->where('title', 'Not Mine')->exists())->toBeFalse()
        ->and($this->teamA->leaders()->pluck('members.id')->all())->toBe([$this->us->id]);
});
