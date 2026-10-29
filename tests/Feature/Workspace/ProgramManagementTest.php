<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->org = Organization::factory()->create();
    $this->otherOrg = Organization::factory()->create();

    $this->adminUser = User::factory()->create(['name' => 'Asha Admin']);
    Member::factory()->linkedTo($this->adminUser)->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);

    $this->managerUser = User::factory()->create();
    $this->manager = Member::factory()->linkedTo($this->managerUser)->create(['organization_id' => $this->org->id]);
});

it('lets an office admin create a program in their organization', function () {
    $this->actingAs($this->adminUser)
        ->post(route('workspace.programs.store'), [
            'name' => 'Revenue',
            'description' => 'Tax work',
            'manager_id' => $this->manager->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('workspace_flash.message', 'Program created.');

    $program = Program::query()->where('name', 'Revenue')->firstOrFail();
    expect($program->organization_id)->toBe($this->org->id)
        ->and($program->manager_id)->toBe($this->manager->id);

    $note = $this->managerUser->notifications()->first();
    expect($note)->not->toBeNull()
        ->and($note->data['kind'])->toBe('made_program_manager')
        ->and($note->data['body'])->toBe('Asha Admin made you the manager of the Revenue program.')
        ->and($note->data['url'])->toBe('/workspace/programs/revenue')
        ->and($this->adminUser->notifications()->count())->toBe(0);
});

it('keeps the program in the office admin organization whatever organization_id they post', function () {
    $this->actingAs($this->adminUser)
        ->post(route('workspace.programs.store'), [
            'name' => 'Sneaky',
            'organization_id' => $this->otherOrg->id,
            'manager_id' => $this->manager->id,
        ])
        ->assertSessionHasNoErrors();

    expect(Program::query()->where('name', 'Sneaky')->firstOrFail()->organization_id)->toBe($this->org->id);

    $outsider = Member::factory()->create(['organization_id' => $this->otherOrg->id]);

    $this->actingAs($this->adminUser)
        ->post(route('workspace.programs.store'), [
            'name' => 'Sneakier',
            'organization_id' => $this->otherOrg->id,
            'manager_id' => $outsider->id,
        ])
        ->assertSessionHasErrors(['manager_id' => "The program manager must be an active person of the program's organization."]);

    expect(Program::query()->where('name', 'Sneakier')->exists())->toBeFalse();
});

it('refuses an inactive or outside manager and a duplicate name', function () {
    $inactive = Member::factory()->create(['organization_id' => $this->org->id, 'is_active' => false]);
    $outsider = Member::factory()->create(['organization_id' => $this->otherOrg->id]);

    foreach ([$inactive, $outsider] as $member) {
        $this->actingAs($this->adminUser)
            ->post(route('workspace.programs.store'), ['name' => 'Nope', 'manager_id' => $member->id])
            ->assertSessionHasErrors(['manager_id' => "The program manager must be an active person of the program's organization."]);
    }

    Program::factory()->create(['organization_id' => $this->org->id, 'name' => 'Revenue', 'manager_id' => $this->manager->id]);

    $this->actingAs($this->adminUser)
        ->post(route('workspace.programs.store'), ['name' => 'Revenue', 'manager_id' => $this->manager->id])
        ->assertSessionHasErrors(['name' => 'That name is already used in this organization.']);

    expect(Program::query()->count())->toBe(1);
});

it('lets a super-admin create a program in any organization', function () {
    $boss = User::factory()->create(['email' => 'boss@example.com']);
    $other = Member::factory()->create(['organization_id' => $this->otherOrg->id]);

    $this->actingAs($boss)
        ->post(route('workspace.programs.store'), [
            'name' => 'Elsewhere',
            'organization_id' => $this->otherOrg->id,
            'manager_id' => $other->id,
        ])
        ->assertSessionHasNoErrors();

    expect(Program::query()->where('name', 'Elsewhere')->firstOrFail()->organization_id)->toBe($this->otherOrg->id);

    $this->actingAs($boss)
        ->post(route('workspace.programs.store'), ['name' => 'Orphan', 'manager_id' => $other->id])
        ->assertSessionHasErrors('organization_id');
});

it('refuses programs to project managers, program managers and team members', function () {
    $program = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->manager->id]);

    $project = Project::factory()->create(['organization_id' => $this->org->id]);
    $team = $project->ensureTeam();
    $pmUser = User::factory()->create();
    $pm = Member::factory()->linkedTo($pmUser)->create(['organization_id' => $this->org->id]);
    $team->members()->attach($pm->id, ['role' => 'leader']);
    $memberUser = User::factory()->create();
    $plain = Member::factory()->linkedTo($memberUser)->create(['organization_id' => $this->org->id]);
    $team->members()->attach($plain->id, ['role' => 'member']);

    foreach ([$pmUser, $this->managerUser, $memberUser] as $user) {
        $this->actingAs($user)
            ->post(route('workspace.programs.store'), ['name' => 'X', 'manager_id' => $this->manager->id])
            ->assertForbidden();
        $this->actingAs($user)
            ->patch(route('workspace.programs.update', $program), ['name' => 'Y'])
            ->assertForbidden();
        $this->actingAs($user)
            ->delete(route('workspace.programs.destroy', $program))
            ->assertForbidden();
    }

    expect($program->fresh()->name)->toBe($program->name);
});

it('notifies a new manager when the program changes hands', function () {
    $program = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->manager->id, 'name' => 'Revenue']);
    $newUser = User::factory()->create();
    $new = Member::factory()->linkedTo($newUser)->create(['organization_id' => $this->org->id]);
    $oldCount = $this->managerUser->notifications()->count();

    $this->actingAs($this->adminUser)
        ->patch(route('workspace.programs.update', $program), ['manager_id' => $new->id])
        ->assertSessionHas('workspace_flash.message', 'Program updated.');

    expect($program->fresh()->manager_id)->toBe($new->id)
        ->and($newUser->notifications()->count())->toBe(1)
        ->and($this->managerUser->notifications()->count())->toBe($oldCount);

    // Renaming alone tells nobody.
    $this->actingAs($this->adminUser)->patch(route('workspace.programs.update', $program), ['description' => 'New']);
    expect($newUser->notifications()->count())->toBe(1);
});

it('deletes a program but keeps its projects', function () {
    $program = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->manager->id]);
    $project = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $program->id]);

    $this->actingAs($this->adminUser)
        ->delete(route('workspace.programs.destroy', $program))
        ->assertSessionHas('workspace_flash.message', 'Program deleted. Its projects stay.');

    expect(Program::query()->count())->toBe(0)
        ->and($project->fresh()->program_id)->toBeNull();
});

it('does not let an office admin touch another organization program', function () {
    $program = Program::factory()->create(['organization_id' => $this->otherOrg->id]);

    $this->actingAs($this->adminUser)->patch(route('workspace.programs.update', $program), ['name' => 'Mine'])->assertForbidden();
    $this->actingAs($this->adminUser)->delete(route('workspace.programs.destroy', $program))->assertForbidden();
});
