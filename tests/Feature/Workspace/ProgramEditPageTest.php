<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * Editing and deleting a program from its page. Organization O: an office
 * admin; program Revenue managed by the joint secretary, with project A
 * (its own manager) in it. Another organization has its own people.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->org = Organization::factory()->create(['name' => 'Ministry']);
    $this->otherOrg = Organization::factory()->create();

    $this->adminUser = User::factory()->create();
    $this->admin = Member::factory()->linkedTo($this->adminUser)->create(['organization_id' => $this->org->id, 'is_office_admin' => true, 'name' => 'Admin']);

    $this->js = User::factory()->create();
    $this->jsMember = Member::factory()->linkedTo($this->js)->create(['organization_id' => $this->org->id, 'name' => 'Joint Secretary', 'title' => 'Joint Secretary']);
    $this->program = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->jsMember->id, 'name' => 'Revenue']);

    $this->projectA = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $this->program->id, 'title' => 'Tax Portal']);
    $this->managerUser = User::factory()->create();
    $this->manager = Member::factory()->linkedTo($this->managerUser)->create(['organization_id' => $this->org->id, 'name' => 'Under Secretary']);
    $this->projectA->ensureTeam()->members()->attach($this->manager->id, ['role' => 'leader']);

    Member::factory()->create(['organization_id' => $this->org->id, 'name' => 'Gone', 'is_active' => false]);
    Member::factory()->create(['organization_id' => $this->otherOrg->id, 'name' => 'Outsider']);
});

it('gives the program page manager candidates only to those who manage programs', function () {
    $expected = [
        ['id' => $this->admin->id, 'name' => 'Admin', 'title' => $this->admin->title],
        ['id' => $this->jsMember->id, 'name' => 'Joint Secretary', 'title' => 'Joint Secretary'],
        ['id' => $this->manager->id, 'name' => 'Under Secretary', 'title' => $this->manager->title],
    ];

    $this->actingAs($this->adminUser)->get("/workspace/programs/{$this->program->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('progress.program.can_manage', true)
            ->where('managerCandidates', $expected));

    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']))->get("/workspace/programs/{$this->program->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('managerCandidates', $expected));

    $this->actingAs($this->js)->get("/workspace/programs/{$this->program->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('progress.program.can_manage', false)
            ->where('managerCandidates', []));

    // A project manager in the program never opens the program page at all.
    $this->actingAs($this->managerUser)->get("/workspace/programs/{$this->program->slug}")
        ->assertForbidden();
});

it('hands the program to a candidate from its page', function () {
    $candidates = $this->actingAs($this->adminUser)->get("/workspace/programs/{$this->program->slug}")
        ->viewData('page')['props']['managerCandidates'];
    $newManagerId = collect($candidates)->firstWhere('name', 'Under Secretary')['id'];

    $this->actingAs($this->adminUser)
        ->from("/workspace/programs/{$this->program->slug}")
        ->patch("/workspace/programs/{$this->program->slug}", [
            'name' => 'Revenue and Customs',
            'description' => 'Taxes and borders',
            'manager_id' => $newManagerId,
        ])
        ->assertRedirect("/workspace/programs/{$this->program->slug}")
        ->assertSessionHasNoErrors();

    expect($this->program->refresh())
        ->manager_id->toBe($this->manager->id)
        ->name->toBe('Revenue and Customs')
        ->description->toBe('Taxes and borders');
});

it('deletes a program from its page and lands on the projects page', function () {
    $this->actingAs($this->adminUser)
        ->from("/workspace/programs/{$this->program->slug}")
        ->delete("/workspace/programs/{$this->program->slug}")
        ->assertRedirect(route('workspace.projects.index'))
        ->assertSessionHas('workspace_flash.success', true);

    expect(Program::query()->whereKey($this->program->id)->exists())->toBeFalse()
        ->and($this->projectA->refresh()->program_id)->toBeNull()
        ->and(Project::query()->whereKey($this->projectA->id)->exists())->toBeTrue();
});
