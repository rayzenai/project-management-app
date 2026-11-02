<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
 * New project takes its people with it: the team starts with the manager
 * as its leader and the people chosen here as members. They pass the same
 * rule as the People tab's add (canJoinTeam): the project's organization,
 * or anyone on a cross-organization team, which only a super-admin opens.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->org = Organization::factory()->create(['name' => 'SDOC']);
    $this->otherOrg = Organization::factory()->create(['name' => 'MoF']);
    $this->admin = User::factory()->create();
    Member::factory()->linkedTo($this->admin)->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);
    $this->boss = User::factory()->create(['email' => 'boss@example.com']);

    $this->manager = Member::factory()->create(['organization_id' => $this->org->id]);
    $this->asha = Member::factory()->create(['organization_id' => $this->org->id]);
    $this->bina = Member::factory()->create(['organization_id' => $this->org->id]);
    $this->outsider = Member::factory()->create(['organization_id' => $this->otherOrg->id]);
});

/** @return array<int, string> member id => role on the project's team */
function createdTeamRoles(string $title): array
{
    $team = Project::query()->where('title', $title)->firstOrFail()->team();

    return $team->members()->orderBy('members.id')->get()
        ->mapWithKeys(fn (Member $m): array => [$m->id => $m->pivot->getAttribute('role')])->all();
}

it('puts the chosen people on the new team, the manager as its leader', function () {
    $this->actingAs($this->admin)->post('/workspace/projects', [
        'title' => 'Roadmap',
        'manager_id' => $this->manager->id,
        // The manager among them keeps leading.
        'member_ids' => [$this->asha->id, $this->bina->id, $this->manager->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(createdTeamRoles('Roadmap'))->toEqual([
        $this->manager->id => 'leader',
        $this->asha->id => 'member',
        $this->bina->id => 'member',
    ]);
});

it('does the same over the API', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->postJson('/api/v1/workspace/projects', [
        'title' => 'Over the wire',
        'manager_id' => $this->manager->id,
        'member_ids' => [$this->asha->id],
    ])->assertCreated();

    expect(createdTeamRoles('Over the wire'))->toEqual([$this->manager->id => 'leader', $this->asha->id => 'member']);
});

it('refuses someone of another organization, someone inactive and someone unknown, and creates nothing', function () {
    $inactive = Member::factory()->create(['organization_id' => $this->org->id, 'is_active' => false]);
    $this->actingAs($this->admin)->from('/workspace/projects');

    foreach ([$this->outsider->id, $inactive->id] as $id) {
        $this->post('/workspace/projects', ['title' => 'Refused', 'manager_id' => $this->manager->id, 'member_ids' => [$this->asha->id, $id]])
            ->assertSessionHasErrors('member_ids');
    }
    $this->post('/workspace/projects', ['title' => 'Refused', 'manager_id' => $this->manager->id, 'member_ids' => [999999]])
        ->assertSessionHasErrors('member_ids.0');

    expect(Project::query()->count())->toBe(0)
        ->and(Team::query()->count())->toBe(0);
});

it('lets a super-admin open the new team to other organizations and bring their people', function () {
    $this->actingAs($this->boss)->post('/workspace/projects', [
        'title' => 'Shared',
        'organization_id' => $this->org->id,
        'manager_id' => $this->manager->id,
        'is_cross_organization' => true,
        'member_ids' => [$this->outsider->id, $this->asha->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $team = Project::query()->where('title', 'Shared')->firstOrFail()->team();

    expect($team->is_cross_organization)->toBeTrue()
        ->and($team->organization_id)->toBe($this->org->id)
        ->and(createdTeamRoles('Shared'))->toEqual([
            $this->manager->id => 'leader',
            $this->asha->id => 'member',
            $this->outsider->id => 'member',
        ]);

    // Without the tick, the team stays the organization's own.
    $this->actingAs($this->boss)->from('/workspace/projects')->post('/workspace/projects', [
        'title' => 'Not shared',
        'organization_id' => $this->org->id,
        'manager_id' => $this->manager->id,
        'member_ids' => [$this->outsider->id],
    ])->assertSessionHasErrors('member_ids');
});

it('ignores an organizational admin\'s cross-organization tick, so their outsiders are refused', function () {
    $this->actingAs($this->admin)->post('/workspace/projects', [
        'title' => 'Mine only',
        'manager_id' => $this->manager->id,
        'is_cross_organization' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Project::query()->where('title', 'Mine only')->firstOrFail()->team()->is_cross_organization)->toBeFalse();

    $this->actingAs($this->admin)->from('/workspace/projects')->post('/workspace/projects', [
        'title' => 'Sneaky',
        'manager_id' => $this->manager->id,
        'is_cross_organization' => true,
        'member_ids' => [$this->outsider->id],
    ])->assertSessionHasErrors('member_ids');

    expect(Project::query()->where('title', 'Sneaky')->exists())->toBeFalse();
});

it('lets a program manager bring their organization\'s people, and nobody else', function () {
    $jsUser = User::factory()->create();
    $js = Member::factory()->linkedTo($jsUser)->create(['organization_id' => $this->org->id]);
    $program = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $js->id]);
    $this->actingAs($jsUser)->from('/workspace/projects');

    $this->post('/workspace/projects', [
        'title' => 'Division work',
        'program_id' => $program->id,
        'manager_id' => $this->manager->id,
        'member_ids' => [$this->asha->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(createdTeamRoles('Division work'))->toEqual([$this->manager->id => 'leader', $this->asha->id => 'member']);

    $this->post('/workspace/projects', [
        'title' => 'Reaching out',
        'program_id' => $program->id,
        'manager_id' => $this->manager->id,
        'is_cross_organization' => true,
        'member_ids' => [$this->outsider->id],
    ])->assertSessionHasErrors('member_ids');
});
