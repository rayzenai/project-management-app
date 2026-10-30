<?php

use App\Models\GitConnection;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\WorkspaceAccess;

/*
 * A program manager (a ministry's Joint Secretary) runs every project in
 * their program as that project's own manager does, without sitting on its
 * team, and reaches nothing else: no other project, no other organization,
 * nobody's account.
 *
 * Organization O: an office admin; program P managed by the joint
 * secretary; project A in P with its own manager (the under secretary) and
 * an officer; project B outside P with its own people. Project X belongs to
 * another organization.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->org = Organization::factory()->create(['name' => 'Ministry']);
    $this->otherOrg = Organization::factory()->create(['name' => 'Elsewhere']);

    $this->adminUser = User::factory()->create();
    Member::factory()->linkedTo($this->adminUser)->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);

    $this->js = User::factory()->create();
    $this->jsMember = Member::factory()->linkedTo($this->js)->create(['organization_id' => $this->org->id, 'title' => 'Joint Secretary']);

    $this->program = Program::factory()->create([
        'organization_id' => $this->org->id,
        'manager_id' => $this->jsMember->id,
        'name' => 'Revenue',
    ]);

    $this->projectA = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $this->program->id, 'title' => 'Tax Portal']);
    $this->teamA = $this->projectA->ensureTeam();
    $this->underSecretary = Member::factory()->create(['organization_id' => $this->org->id, 'title' => 'Under Secretary']);
    $this->officer = Member::factory()->create(['organization_id' => $this->org->id, 'title' => 'Officer']);
    $this->teamA->members()->attach($this->underSecretary->id, ['role' => 'leader']);
    $this->teamA->members()->attach($this->officer->id, ['role' => 'member']);

    $this->projectB = Project::factory()->create(['organization_id' => $this->org->id, 'title' => 'Customs']);
    $this->teamB = $this->projectB->ensureTeam();
    $this->managerB = Member::factory()->create(['organization_id' => $this->org->id]);
    $this->officerB = Member::factory()->create(['organization_id' => $this->org->id]);
    $this->teamB->members()->attach($this->managerB->id, ['role' => 'leader']);
    $this->teamB->members()->attach($this->officerB->id, ['role' => 'member']);

    $this->projectX = Project::factory()->create(['organization_id' => $this->otherOrg->id, 'title' => 'Foreign']);
    $this->teamX = $this->projectX->ensureTeam();
    $this->managerX = Member::factory()->create(['organization_id' => $this->otherOrg->id]);
    $this->teamX->members()->attach($this->managerX->id, ['role' => 'leader']);
});

function programAccessGit(Organization $organization): GitConnection
{
    return GitConnection::query()->create([
        'organization_id' => $organization->id,
        'base_url' => 'https://git.example',
        'owner' => 'ministry',
        'token' => 'bot-token-never-shown',
        'webhook_id' => 99,
        'webhook_secret' => 'hook-secret-never-shown',
    ]);
}

/**
 * @return list<int>
 */
function programAccessSorted(iterable $ids): array
{
    $ids = array_map(fn ($id): int => (int) $id, [...$ids]);
    sort($ids);

    return $ids;
}

it('lets a program manager see, edit, archive and see the progress of every project in the program', function () {
    expect(WorkspaceAccess::canViewProject($this->js, $this->projectA))->toBeTrue()
        ->and(WorkspaceAccess::canManageProjectAccess($this->js, $this->projectA))->toBeTrue()
        ->and(WorkspaceAccess::canViewProjectProgress($this->js, $this->projectA))->toBeTrue()
        ->and(WorkspaceAccess::canArchiveProject($this->js, $this->projectA))->toBeTrue()
        ->and(WorkspaceAccess::managesProgramOf($this->js, $this->projectA))->toBeTrue()
        ->and(WorkspaceAccess::managedProgramIds($this->js))->toBe([$this->program->id]);

    $this->actingAs($this->js)->get("/workspace/projects/{$this->projectA->slug}")->assertOk();
    $this->actingAs($this->js)->get("/workspace/projects/{$this->projectA->slug}/progress")->assertOk();
    $this->actingAs($this->js, 'sanctum')->getJson("/api/v1/workspace/projects/{$this->projectA->slug}")->assertOk();

    $this->actingAs($this->js)->patch("/workspace/projects/{$this->projectA->slug}", ['title' => 'Tax Portal Two'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->projectA->fresh()->title)->toBe('Tax Portal Two')
        ->and($this->teamA->members()->whereKey($this->jsMember->id)->exists())->toBeFalse();
});

it('keeps a program manager out of projects outside the program', function () {
    // A project of another organization pointed at the program by bad data
    // stays out of reach too: a program reaches only its own organization.
    $stray = Project::factory()->create(['organization_id' => $this->otherOrg->id, 'program_id' => $this->program->id]);

    foreach ([$this->projectB, $this->projectX, $stray] as $project) {
        expect(WorkspaceAccess::canViewProject($this->js, $project))->toBeFalse()
            ->and(WorkspaceAccess::canManageProjectAccess($this->js, $project))->toBeFalse()
            ->and(WorkspaceAccess::canViewProjectProgress($this->js, $project))->toBeFalse()
            ->and(WorkspaceAccess::canArchiveProject($this->js, $project))->toBeFalse()
            ->and(WorkspaceAccess::managesProgramOf($this->js, $project))->toBeFalse();
    }

    expect(WorkspaceAccess::canManageRosterOf($this->js, $this->teamB))->toBeFalse()
        ->and(WorkspaceAccess::canManageRosterOf($this->js, $this->teamX))->toBeFalse()
        ->and(WorkspaceAccess::canManageRosterOf($this->js, $stray->ensureTeam()))->toBeFalse();

    $this->actingAs($this->js)->get("/workspace/projects/{$this->projectB->slug}")->assertForbidden();
    $this->actingAs($this->js)->get("/workspace/projects/{$this->projectX->slug}")->assertForbidden();
    $this->actingAs($this->js)->get("/workspace/projects/{$this->projectB->slug}/progress")->assertForbidden();
    $this->actingAs($this->js)->patch("/workspace/projects/{$this->projectB->slug}", ['title' => 'Hijacked'])->assertForbidden();

    expect($this->projectB->fresh()->title)->toBe('Customs');
});

it('lets a program manager manage the roster of the program projects but never remove the manager', function () {
    expect(WorkspaceAccess::canManageRosterOf($this->js, $this->teamA))->toBeTrue()
        ->and(WorkspaceAccess::canViewTeamProgress($this->js, $this->teamA))->toBeTrue()
        ->and(WorkspaceAccess::canRemoveFromTeam($this->js, $this->teamA, $this->officer))->toBeTrue()
        ->and(WorkspaceAccess::canRemoveFromTeam($this->js, $this->teamA, $this->underSecretary))->toBeFalse()
        ->and(WorkspaceAccess::canEditTeam($this->js, $this->teamA))->toBeFalse()
        ->and(WorkspaceAccess::leadsTeam($this->js, $this->teamA))->toBeFalse();

    $colleague = Member::factory()->create(['organization_id' => $this->org->id]);

    $this->actingAs($this->js)->post("/workspace/teams/{$this->teamA->id}/members", ['member_id' => $colleague->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->actingAs($this->js)->delete("/workspace/teams/{$this->teamA->id}/members/{$this->officer->id}")
        ->assertRedirect();

    // Never the manager removed (replacing one: ProgramManagerBuildsTest).
    $this->actingAs($this->js)->delete("/workspace/teams/{$this->teamA->id}/members/{$this->underSecretary->id}")
        ->assertForbidden();

    expect($this->teamA->members()->whereKey($colleague->id)->exists())->toBeTrue()
        ->and($this->teamA->members()->whereKey($this->officer->id)->exists())->toBeFalse()
        ->and($this->teamA->leaders()->pluck('members.id')->all())->toBe([$this->underSecretary->id])
        ->and($this->teamA->members()->whereKey($this->jsMember->id)->exists())->toBeFalse();
});

it('gives a program manager no power the office admin keeps', function () {
    expect(WorkspaceAccess::canPublishProject($this->js, $this->org->id))->toBeFalse()
        ->and(WorkspaceAccess::canChangeProjectKey($this->js, $this->projectA))->toBeFalse()
        ->and(WorkspaceAccess::canManagePrograms($this->js, $this->org->id))->toBeFalse()
        ->and(WorkspaceAccess::canCreateMember($this->js, $this->org->id))->toBeFalse();

    foreach (Member::query()->where('organization_id', $this->org->id)->get() as $member) {
        expect(WorkspaceAccess::canManageMember($this->js, $member))->toBeFalse();
    }

    // Creating outside their programs (in them: ProgramManagerBuildsTest).
    $this->actingAs($this->js)->post('/workspace/projects', ['title' => 'Mine', 'manager_id' => $this->officer->id])
        ->assertSessionHasErrors(['program_id' => 'Choose one of your programs.']);
    $this->actingAs($this->js)->patch("/workspace/members/{$this->officer->id}", ['name' => 'Renamed'])->assertForbidden();
    $this->actingAs($this->js)->patch("/workspace/members/{$this->underSecretary->id}", ['password' => 'taken-over-1'])->assertForbidden();

    $key = $this->projectA->fresh()->key;
    $this->actingAs($this->js)->patch("/workspace/projects/{$this->projectA->slug}", ['is_public' => true, 'key' => 'ZZZ'])
        ->assertSessionHasNoErrors();

    expect(Project::query()->where('title', 'Mine')->exists())->toBeFalse()
        ->and($this->officer->fresh()->name)->not->toBe('Renamed')
        ->and($this->projectA->fresh()->is_public)->toBeFalse()
        ->and($this->projectA->fresh()->key)->toBe($key);
});

it('takes everything away from a deactivated program manager', function () {
    $this->jsMember->update(['is_active' => false]);
    WorkspaceAccess::flush();

    expect(WorkspaceAccess::managedProgramIds($this->js))->toBe([])
        ->and(WorkspaceAccess::managesProgramOf($this->js, $this->projectA))->toBeFalse()
        ->and(WorkspaceAccess::canViewProject($this->js, $this->projectA))->toBeFalse()
        ->and(WorkspaceAccess::canManageProjectAccess($this->js, $this->projectA))->toBeFalse()
        ->and(WorkspaceAccess::canViewProjectProgress($this->js, $this->projectA))->toBeFalse()
        ->and(WorkspaceAccess::canArchiveProject($this->js, $this->projectA))->toBeFalse()
        ->and(WorkspaceAccess::canManageRosterOf($this->js, $this->teamA))->toBeFalse()
        ->and(WorkspaceAccess::canRemoveFromTeam($this->js, $this->teamA, $this->officer))->toBeFalse()
        ->and(WorkspaceAccess::canViewProgram($this->js, $this->program))->toBeFalse()
        ->and(Project::query()->visibleTo($this->js)->pluck('id')->all())->not->toContain($this->projectA->id)
        ->and(Team::query()->visibleTo($this->js)->pluck('id')->all())->not->toContain($this->teamA->id)
        ->and(Member::query()->visibleTo($this->js)->pluck('id')->all())->not->toContain($this->officer->id)
        ->and(Program::query()->visibleTo($this->js)->count())->toBe(0);

    $this->actingAs($this->js)->get("/workspace/projects/{$this->projectA->slug}")->assertForbidden();
});

it('ends access when the project leaves the program or the program is deleted', function () {
    $second = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $this->program->id]);

    $this->projectA->update(['program_id' => null]);
    WorkspaceAccess::flush();

    expect(WorkspaceAccess::canViewProject($this->js, $this->projectA->fresh()))->toBeFalse()
        ->and(WorkspaceAccess::canManageRosterOf($this->js, $this->teamA))->toBeFalse()
        ->and(WorkspaceAccess::canViewProject($this->js, $second))->toBeTrue();

    $this->program->delete();
    WorkspaceAccess::flush();

    expect(WorkspaceAccess::managedProgramIds($this->js))->toBe([])
        ->and(WorkspaceAccess::canViewProject($this->js, $second->fresh()))->toBeFalse()
        ->and(Project::query()->visibleTo($this->js)->count())->toBe(0)
        ->and(Project::query()->whereKey([$this->projectA->id, $second->id])->count())->toBe(2);
});

it('matches the visibility scope to canViewProject', function () {
    Project::factory()->public()->create(['organization_id' => $this->org->id]);
    Project::factory()->public()->create(['organization_id' => $this->otherOrg->id]);
    Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $this->program->id]);
    Project::factory()->create(['organization_id' => $this->otherOrg->id, 'program_id' => $this->program->id]);

    $visible = programAccessSorted(Project::query()->visibleTo($this->js)->pluck('id'));
    $allowed = programAccessSorted(Project::query()->get()
        ->filter(fn (Project $project): bool => WorkspaceAccess::canViewProject($this->js, $project))
        ->pluck('id'));

    expect($visible)->toBe($allowed)
        ->and($visible)->toContain($this->projectA->id)
        ->and($visible)->not->toContain($this->projectB->id)
        ->and($visible)->not->toContain($this->projectX->id);
});

it('shows a program manager the teams and people of the program projects only', function () {
    $teams = Team::query()->visibleTo($this->js)->pluck('id')->all();
    $people = Member::query()->visibleTo($this->js)->pluck('id')->all();

    expect($teams)->toContain($this->teamA->id)
        ->and($teams)->not->toContain($this->teamB->id)
        ->and($teams)->not->toContain($this->teamX->id)
        ->and($people)->toContain($this->underSecretary->id)
        ->and($people)->toContain($this->officer->id)
        ->and($people)->toContain($this->jsMember->id)
        ->and($people)->not->toContain($this->managerB->id)
        ->and($people)->not->toContain($this->officerB->id)
        ->and($people)->not->toContain($this->managerX->id);
});

it('shows a program to its manager and the people who administer its organization only', function () {
    $otherProgram = Program::factory()->create(['organization_id' => $this->org->id]);
    $foreignProgram = Program::factory()->create(['organization_id' => $this->otherOrg->id]);
    $boss = User::factory()->create(['email' => 'boss@example.com']);

    expect(WorkspaceAccess::canViewProgram($this->js, $this->program))->toBeTrue()
        ->and(WorkspaceAccess::canViewProgram($this->js, $otherProgram))->toBeFalse()
        ->and(WorkspaceAccess::canViewProgram($this->js, $foreignProgram))->toBeFalse()
        ->and(programAccessSorted(Program::query()->visibleTo($this->js)->pluck('id')))->toBe([$this->program->id])
        ->and(WorkspaceAccess::canViewProgram($this->adminUser, $otherProgram))->toBeTrue()
        ->and(WorkspaceAccess::canViewProgram($this->adminUser, $foreignProgram))->toBeFalse()
        ->and(programAccessSorted(Program::query()->visibleTo($this->adminUser)->pluck('id')))->toBe(programAccessSorted([$this->program->id, $otherProgram->id]))
        ->and(WorkspaceAccess::canManagePrograms($this->adminUser, $this->org->id))->toBeTrue()
        ->and(WorkspaceAccess::canManagePrograms($this->adminUser, $this->otherOrg->id))->toBeFalse()
        ->and(WorkspaceAccess::canManagePrograms($boss, $this->otherOrg->id))->toBeTrue()
        ->and(Program::query()->visibleTo($boss)->count())->toBe(3);
});

it('lets a program manager see and link the code of the program projects', function () {
    expect(WorkspaceAccess::canViewProjectCode($this->js, $this->projectA))->toBeFalse()
        ->and(WorkspaceAccess::canManageProjectRepositories($this->js, $this->projectA))->toBeFalse()
        ->and(Project::query()->codeVisibleTo($this->js)->count())->toBe(0);

    programAccessGit($this->org);
    WorkspaceAccess::flush();

    expect(WorkspaceAccess::canViewProjectCode($this->js, $this->projectA))->toBeTrue()
        ->and(WorkspaceAccess::canManageProjectRepositories($this->js, $this->projectA))->toBeTrue()
        ->and(WorkspaceAccess::canViewProjectCode($this->js, $this->projectB))->toBeFalse()
        ->and(WorkspaceAccess::canManageProjectRepositories($this->js, $this->projectB))->toBeFalse();

    $visible = programAccessSorted(Project::query()->codeVisibleTo($this->js)->pluck('id'));
    $allowed = programAccessSorted(Project::query()->get()
        ->filter(fn (Project $project): bool => WorkspaceAccess::canViewProjectCode($this->js, $project))
        ->pluck('id'));

    expect($visible)->toBe($allowed)
        ->and($visible)->toBe([$this->projectA->id]);
});

it('never lets a program reach past its manager organization', function () {
    $boss = User::factory()->create(['email' => 'boss@example.com']);

    $this->actingAs($boss)
        ->patch("/workspace/members/{$this->jsMember->id}", ['organization_id' => $this->otherOrg->id])
        ->assertSessionHasErrors(['organization_id' => 'Choose another manager for the Revenue program first.']);

    expect($this->jsMember->fresh()->organization_id)->toBe($this->org->id);

    Member::query()->whereKey($this->jsMember->id)->update(['organization_id' => $this->otherOrg->id]);
    WorkspaceAccess::flush();

    expect(WorkspaceAccess::managedProgramIds($this->js))->toBe([])
        ->and(WorkspaceAccess::canViewProject($this->js, $this->projectA))->toBeFalse()
        ->and(WorkspaceAccess::canManageProjectAccess($this->js, $this->projectA))->toBeFalse()
        ->and(WorkspaceAccess::canManageRosterOf($this->js, $this->teamA))->toBeFalse()
        ->and(WorkspaceAccess::canViewProgram($this->js, $this->program))->toBeFalse()
        ->and(Project::query()->visibleTo($this->js)->pluck('id')->all())->not->toContain($this->projectA->id);
});

it('names every program a manager must hand over before moving organization', function () {
    $boss = User::factory()->create(['email' => 'boss@example.com']);
    Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->jsMember->id, 'name' => 'Customs']);

    $this->actingAs($boss)
        ->patch("/workspace/members/{$this->jsMember->id}", ['organization_id' => $this->otherOrg->id])
        ->assertSessionHasErrors(['organization_id' => 'Choose another manager for the Customs, Revenue programs first.']);

    expect($this->jsMember->fresh()->organization_id)->toBe($this->org->id);
});

it('lets a person who manages no program move organization as before', function () {
    $boss = User::factory()->create(['email' => 'boss@example.com']);
    $loose = Member::factory()->create(['organization_id' => $this->org->id]);

    $this->actingAs($boss)
        ->patch("/workspace/members/{$loose->id}", ['organization_id' => $this->otherOrg->id])
        ->assertSessionHasNoErrors();

    expect($loose->fresh()->organization_id)->toBe($this->otherOrg->id);
});

it('gives a program manager on a cross-organization team what its manager has', function () {
    $outsider = Member::factory()->create(['organization_id' => $this->otherOrg->id, 'name' => 'Visiting Expert']);

    // Not cross-organization: the same add is refused, and search never finds them.
    $this->actingAs($this->js)->getJson("/workspace/teams/{$this->teamA->id}/candidates?q=Visiting")
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->actingAs($this->js)->post("/workspace/teams/{$this->teamA->id}/members", ['member_id' => $outsider->id])
        ->assertSessionHasErrors('member_id');

    expect($this->teamA->members()->whereKey($outsider->id)->exists())->toBeFalse();

    $this->teamA->update(['is_cross_organization' => true]);

    expect(WorkspaceAccess::canManageRosterOf($this->js, $this->teamA))->toBeTrue();

    $this->actingAs($this->js)->getJson("/workspace/teams/{$this->teamA->id}/candidates?q=Visiting")
        ->assertOk()
        ->assertJsonPath('data.0.id', $outsider->id);
    $this->actingAs($this->js)->post("/workspace/teams/{$this->teamA->id}/members", ['member_id' => $outsider->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->teamA->members()->whereKey($outsider->id)->exists())->toBeTrue()
        ->and(WorkspaceAccess::canManageMember($this->js, $outsider))->toBeFalse();

    $this->actingAs($this->js)->patch("/workspace/members/{$outsider->id}", ['password' => 'taken-over-1'])->assertForbidden();
    $this->actingAs($this->js)->delete("/workspace/teams/{$this->teamA->id}/members/{$outsider->id}")->assertRedirect();

    expect($this->teamA->members()->whereKey($outsider->id)->exists())->toBeFalse();
});
