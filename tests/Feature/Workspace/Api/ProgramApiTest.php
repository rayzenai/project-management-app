<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->org = Organization::factory()->create();
    $this->otherOrg = Organization::factory()->create();

    $this->adminUser = User::factory()->create();
    Member::factory()->linkedTo($this->adminUser)->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);

    $this->managerUser = User::factory()->create();
    $this->manager = Member::factory()->linkedTo($this->managerUser)->create(['organization_id' => $this->org->id]);
});

it('lists only the programs the caller may see', function () {
    $mine = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->manager->id, 'name' => 'Mine']);
    Program::factory()->create(['organization_id' => $this->org->id, 'name' => 'Same office']);
    Program::factory()->create(['organization_id' => $this->otherOrg->id, 'name' => 'Foreign']);
    Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $mine->id]);

    Sanctum::actingAs($this->adminUser, ['*']);
    $names = collect($this->getJson('/api/v1/workspace/programs')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
    expect($names)->toBe(['Mine', 'Same office']);

    Sanctum::actingAs($this->managerUser, ['*']);
    $rows = $this->getJson('/api/v1/workspace/programs')->assertOk()->json('data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Mine')
        ->and($rows[0]['projects_count'])->toBe(1)
        ->and($rows[0]['can_manage'])->toBeFalse()
        ->and($rows[0]['manager']['id'])->toBe($this->manager->id);

    $plainUser = User::factory()->create();
    Member::factory()->linkedTo($plainUser)->create(['organization_id' => $this->org->id]);
    Sanctum::actingAs($plainUser, ['*']);
    expect($this->getJson('/api/v1/workspace/programs')->assertOk()->json('data'))->toBe([]);
});

it('creates, updates and deletes a program', function () {
    Sanctum::actingAs($this->adminUser, ['*']);

    $response = $this->postJson('/api/v1/workspace/programs', [
        'name' => 'Revenue',
        'manager_id' => $this->manager->id,
    ])->assertCreated()
        ->assertJsonPath('message', 'Program created.')
        ->assertJsonPath('data.name', 'Revenue')
        ->assertJsonPath('data.slug', 'revenue')
        ->assertJsonPath('data.organization.id', $this->org->id)
        ->assertJsonPath('data.can_manage', true);

    $this->patchJson('/api/v1/workspace/programs/revenue', ['name' => 'Revenue 2'])
        ->assertOk()
        ->assertJsonPath('message', 'Program updated.')
        ->assertJsonPath('data.name', 'Revenue 2');

    $project = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $response->json('data.id')]);

    $this->deleteJson('/api/v1/workspace/programs/revenue')
        ->assertOk()
        ->assertJsonPath('message', 'Program deleted. Its projects stay.');

    expect(Program::query()->count())->toBe(0)
        ->and($project->fresh()->program_id)->toBeNull();
});

it('validates on the API and keeps an office admin to their organization', function () {
    Sanctum::actingAs($this->adminUser, ['*']);
    $outsider = Member::factory()->create(['organization_id' => $this->otherOrg->id]);

    $this->postJson('/api/v1/workspace/programs', ['name' => 'A', 'manager_id' => $outsider->id])
        ->assertUnprocessable()->assertJsonValidationErrors('manager_id');

    $this->postJson('/api/v1/workspace/programs', ['name' => 'A', 'organization_id' => $this->otherOrg->id, 'manager_id' => $this->manager->id])
        ->assertCreated()->assertJsonPath('data.organization.id', $this->org->id);
});

it('forbids a member and a program manager from changing programs', function () {
    $program = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->manager->id]);
    $plainUser = User::factory()->create();
    Member::factory()->linkedTo($plainUser)->create(['organization_id' => $this->org->id]);

    foreach ([$plainUser, $this->managerUser] as $user) {
        Sanctum::actingAs($user, ['*']);
        $this->postJson('/api/v1/workspace/programs', ['name' => 'X', 'manager_id' => $this->manager->id])->assertForbidden();
        $this->patchJson("/api/v1/workspace/programs/{$program->slug}", ['name' => 'Y'])->assertForbidden();
        $this->deleteJson("/api/v1/workspace/programs/{$program->slug}")->assertForbidden();
    }
});
