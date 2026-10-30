<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AddedToProjectTeam;
use App\Notifications\MadeProgramManager;
use App\Notifications\MadeProjectManager;
use App\Support\WorkspaceAccess;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * Roles on the person form: whoever may manage a person (their
 * organization's organizational admin, or a super-admin) makes them a
 * program manager, a project manager or a member of the organization's
 * projects. A manager role is handed over, never taken away, and nothing
 * reaches another organization.
 *
 * Organization O: an organizational admin; program Revenue managed by the
 * joint secretary; project Tax Portal (A, in Revenue) managed by the under
 * secretary, with an officer; project Customs (B) with its own manager and
 * the person being edited. Organization E: program Foreign Affairs, project
 * Embassy Portal (X) on an ordinary team and project Joint Desk (Y) on a
 * cross-organization team.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    Mail::fake();

    $this->org = Organization::factory()->create(['name' => 'Ministry']);
    $this->otherOrg = Organization::factory()->create(['name' => 'Elsewhere']);

    $this->admin = User::factory()->create();
    Member::factory()->linkedTo($this->admin)->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);

    $this->jointSecretary = Member::factory()->withUser()->create(['organization_id' => $this->org->id]);
    $this->program = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->jointSecretary->id, 'name' => 'Revenue']);

    $this->projectA = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $this->program->id, 'title' => 'Tax Portal']);
    $this->teamA = $this->projectA->ensureTeam();
    $this->underSecretary = Member::factory()->withUser()->create(['organization_id' => $this->org->id]);
    $this->officer = Member::factory()->withUser()->create(['organization_id' => $this->org->id]);
    $this->teamA->members()->attach($this->underSecretary->id, ['role' => 'leader']);
    $this->teamA->members()->attach($this->officer->id, ['role' => 'member']);

    $this->projectB = Project::factory()->create(['organization_id' => $this->org->id, 'title' => 'Customs']);
    $this->teamB = $this->projectB->ensureTeam();
    $this->managerB = Member::factory()->withUser()->create(['organization_id' => $this->org->id]);
    $this->person = Member::factory()->withUser()->create(['organization_id' => $this->org->id]);
    $this->teamB->members()->attach($this->managerB->id, ['role' => 'leader']);
    $this->teamB->members()->attach($this->person->id, ['role' => 'member']);

    $this->foreignProgram = Program::factory()->create(['organization_id' => $this->otherOrg->id, 'name' => 'Foreign Affairs']);
    $this->projectX = Project::factory()->create(['organization_id' => $this->otherOrg->id, 'title' => 'Embassy Portal']);
    $this->teamX = $this->projectX->ensureTeam();
    $this->managerX = Member::factory()->create(['organization_id' => $this->otherOrg->id]);
    $this->teamX->members()->attach($this->managerX->id, ['role' => 'leader']);
    $this->projectY = Project::factory()->create(['organization_id' => $this->otherOrg->id, 'title' => 'Joint Desk']);
    $this->teamY = $this->projectY->ensureTeam();
    $this->teamY->update(['is_cross_organization' => true]);
    $this->managerY = Member::factory()->create(['organization_id' => $this->otherOrg->id]);
    $this->teamY->members()->attach($this->managerY->id, ['role' => 'leader']);

    // Only what the tests do is notified.
    Notification::fake();
});

function memberRolesBoss(): User
{
    return User::query()->firstOrCreate(['email' => 'boss@example.com'], ['name' => 'Boss', 'password' => 'secret-pass-123']);
}

/**
 * @return list<int>
 */
function memberRolesTeamIds(Member $member): array
{
    $ids = $member->teams()->pluck('teams.id')->map(fn ($id): int => (int) $id)->all();
    sort($ids);

    return $ids;
}

/**
 * @return list<int>
 */
function memberRolesLeaderIds(Team $team): array
{
    return $team->leaders()->pluck('members.id')->map(fn ($id): int => (int) $id)->all();
}

function memberRolesRole(Team $team, Member $member): ?string
{
    return DB::table('member_team')->where('team_id', $team->id)->where('member_id', $member->id)->value('role');
}

/**
 * @return list<int>
 */
function memberRolesSorted(int ...$ids): array
{
    sort($ids);

    return $ids;
}

// ---- Setting roles ----

it('lets an organizational admin make someone a program manager in place of the old one', function () {
    $this->actingAs($this->admin)
        ->patch("/workspace/members/{$this->person->id}", ['managed_program_ids' => [$this->program->id]])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash.message', 'Member updated. Roles saved.');

    expect($this->program->fresh()->manager_id)->toBe($this->person->id);

    Notification::assertSentTo($this->person->user, MadeProgramManager::class, fn (MadeProgramManager $n): bool => $n->program->is($this->program));
    Notification::assertNotSentTo($this->jointSecretary->user, MadeProgramManager::class);
});

it('lets an organizational admin make someone a project manager, keeping the old manager on the team', function () {
    $this->actingAs($this->admin)
        ->patch("/workspace/members/{$this->person->id}", ['managed_project_ids' => [$this->projectA->id]])
        ->assertSessionHasNoErrors();

    expect(memberRolesLeaderIds($this->teamA))->toBe([$this->person->id])
        ->and(memberRolesRole($this->teamA, $this->underSecretary))->toBe('member')
        ->and(memberRolesTeamIds($this->person))->toBe(memberRolesSorted($this->teamA->id, $this->teamB->id));

    Notification::assertSentTo($this->person->user, MadeProjectManager::class, fn (MadeProjectManager $n): bool => $n->project->is($this->projectA));
});

it('makes a teammate the manager once, with the old manager staying a member (Review Focus 1)', function () {
    $this->actingAs($this->admin)
        ->patch("/workspace/members/{$this->officer->id}", [
            'managed_project_ids' => [$this->projectA->id],
            'team_ids' => [$this->teamA->id],
        ])
        ->assertSessionHasNoErrors();

    expect(DB::table('member_team')->where('team_id', $this->teamA->id)->where('member_id', $this->officer->id)->count())->toBe(1)
        ->and(memberRolesRole($this->teamA, $this->officer))->toBe('leader')
        ->and(memberRolesRole($this->teamA, $this->underSecretary))->toBe('member')
        ->and(memberRolesLeaderIds($this->teamA))->toBe([$this->officer->id]);
});

it('puts a person on a team and takes them off another', function () {
    $this->actingAs($this->admin)
        ->patch("/workspace/members/{$this->person->id}", ['team_ids' => [$this->teamA->id]])
        ->assertSessionHasNoErrors();

    expect(memberRolesTeamIds($this->person))->toBe([$this->teamA->id])
        ->and(memberRolesRole($this->teamA, $this->person))->toBe('member')
        ->and(memberRolesLeaderIds($this->teamB))->toBe([$this->managerB->id]);

    Notification::assertSentTo($this->person->user, AddedToProjectTeam::class, fn (AddedToProjectTeam $n): bool => $n->project->is($this->projectA));
});

it('keeps the manager role when the same save takes them off that team (Review Focus 2)', function () {
    $this->actingAs($this->admin)
        ->patch("/workspace/members/{$this->person->id}", [
            'managed_project_ids' => [$this->projectA->id],
            'team_ids' => [],
        ])
        ->assertSessionHasNoErrors();

    expect(memberRolesLeaderIds($this->teamA))->toBe([$this->person->id])
        ->and(memberRolesTeamIds($this->person))->toBe([$this->teamA->id]);

    $this->actingAs($this->admin)
        ->patch("/workspace/members/{$this->managerB->id}", ['team_ids' => []])
        ->assertSessionHasNoErrors();

    expect(memberRolesLeaderIds($this->teamB))->toBe([$this->managerB->id]);
});

// ---- Refusals ----

it('refuses to take a program from its manager without a new one, changing nothing', function () {
    $this->actingAs($this->admin)
        ->patch("/workspace/members/{$this->jointSecretary->id}", ['name' => 'Renamed', 'managed_program_ids' => []])
        ->assertSessionHasErrors(['managed_program_ids' => 'Choose another manager for the Revenue program first.']);

    expect($this->program->fresh()->manager_id)->toBe($this->jointSecretary->id)
        ->and($this->jointSecretary->fresh()->name)->not->toBe('Renamed');
});

it('refuses to take a project from its manager without a new one, changing nothing', function () {
    $this->actingAs($this->admin)
        ->patch("/workspace/members/{$this->underSecretary->id}", ['name' => 'Renamed', 'managed_project_ids' => []])
        ->assertSessionHasErrors(['managed_project_ids' => 'Choose another manager for Tax Portal (Revenue) first.']);

    expect(memberRolesLeaderIds($this->teamA))->toBe([$this->underSecretary->id])
        ->and($this->underSecretary->fresh()->name)->not->toBe('Renamed');
});

it('only lets an active person manage', function () {
    $inactive = Member::factory()->inactive()->create(['organization_id' => $this->org->id]);
    $this->actingAs($this->admin);

    $this->patch("/workspace/members/{$inactive->id}", ['managed_program_ids' => [$this->program->id]])
        ->assertSessionHasErrors(['managed_program_ids' => 'Only an active person can manage.']);
    $this->patch("/workspace/members/{$inactive->id}", ['managed_project_ids' => [$this->projectA->id]])
        ->assertSessionHasErrors(['managed_project_ids' => 'Only an active person can manage.']);
    $this->patch("/workspace/members/{$this->person->id}", ['is_active' => false, 'managed_project_ids' => [$this->projectA->id]])
        ->assertSessionHasErrors(['managed_project_ids' => 'Only an active person can manage.']);

    expect($this->program->fresh()->manager_id)->toBe($this->jointSecretary->id)
        ->and(memberRolesLeaderIds($this->teamA))->toBe([$this->underSecretary->id])
        ->and($this->person->fresh()->is_active)->toBeTrue();
});

it('keeps an organizational admin away from another organization\'s programs, projects and teams', function () {
    $this->actingAs($this->admin);

    $this->patch("/workspace/members/{$this->person->id}", ['managed_program_ids' => [$this->foreignProgram->id]])
        ->assertSessionHasErrors(['managed_program_ids' => 'The Foreign Affairs program belongs to another organization.']);
    // Joint Desk takes anyone, but the admin does not run Elsewhere.
    $this->patch("/workspace/members/{$this->person->id}", ['managed_project_ids' => [$this->projectY->id]])
        ->assertSessionHasErrors(['managed_project_ids' => 'Joint Desk belongs to another organization.']);
    $this->patch("/workspace/members/{$this->person->id}", ['team_ids' => [$this->teamB->id, $this->teamY->id]])
        ->assertSessionHasErrors(['team_ids' => 'Joint Desk belongs to another organization.']);
    $this->patch("/workspace/members/{$this->person->id}", ['team_ids' => [$this->teamB->id, $this->teamX->id]])
        ->assertSessionHasErrors(['team_ids' => 'Embassy Portal belongs to another organization.']);

    expect($this->foreignProgram->fresh()->manager_id)->not->toBe($this->person->id)
        ->and(memberRolesLeaderIds($this->teamY))->toBe([$this->managerY->id])
        ->and(memberRolesTeamIds($this->person))->toBe([$this->teamB->id]);
});

it('will not let an organizational admin take their person off another organization\'s team', function () {
    $this->teamY->members()->attach($this->person->id, ['role' => 'member']);
    $this->actingAs($this->admin);

    $this->patch("/workspace/members/{$this->person->id}", ['team_ids' => [$this->teamB->id]])
        ->assertSessionHasErrors(['team_ids' => 'Joint Desk belongs to another organization.']);

    expect(memberRolesTeamIds($this->person))->toBe(memberRolesSorted($this->teamB->id, $this->teamY->id));

    // Left as it is, the other organization's team is no change.
    $this->patch("/workspace/members/{$this->person->id}", ['team_ids' => [$this->teamA->id, $this->teamY->id]])
        ->assertSessionHasNoErrors();

    expect(memberRolesTeamIds($this->person))->toBe(memberRolesSorted($this->teamA->id, $this->teamY->id));
});

it('keeps the role fields from anyone who may not manage the person', function () {
    $foreigner = Member::factory()->create(['organization_id' => $this->otherOrg->id]);
    $peer = Member::factory()->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);

    $this->actingAs($this->admin)
        ->patch("/workspace/members/{$foreigner->id}", ['team_ids' => [$this->teamY->id]])
        ->assertForbidden();
    $this->actingAs($this->admin)
        ->patch("/workspace/members/{$peer->id}", ['managed_project_ids' => [$this->projectA->id]])
        ->assertForbidden();
    // A program manager and a project manager manage no people.
    $this->actingAs($this->jointSecretary->user)
        ->patch("/workspace/members/{$this->officer->id}", ['managed_project_ids' => [$this->projectA->id]])
        ->assertForbidden();
    $this->actingAs($this->underSecretary->user)
        ->patch("/workspace/members/{$this->officer->id}", ['team_ids' => []])
        ->assertForbidden();

    expect($foreigner->teams()->exists())->toBeFalse()
        ->and($peer->teams()->exists())->toBeFalse()
        ->and(memberRolesLeaderIds($this->teamA))->toBe([$this->underSecretary->id])
        ->and(memberRolesTeamIds($this->officer))->toBe([$this->teamA->id]);
});

it('keeps the organization and the organizational-admin flag for super-admins', function () {
    $this->actingAs($this->admin)
        ->patch("/workspace/members/{$this->person->id}", ['team_ids' => [$this->teamB->id], 'is_office_admin' => true])
        ->assertForbidden();

    expect($this->person->fresh()->is_office_admin)->toBeFalse();
});

// ---- Super-admins ----

it('lets a super-admin put someone on another organization\'s cross-organization team, never an ordinary one', function () {
    $this->actingAs(memberRolesBoss());

    $this->patch("/workspace/members/{$this->person->id}", ['team_ids' => [$this->teamB->id, $this->teamY->id]])
        ->assertSessionHasNoErrors();

    expect(memberRolesTeamIds($this->person))->toBe(memberRolesSorted($this->teamB->id, $this->teamY->id));

    $this->patch("/workspace/members/{$this->person->id}", ['team_ids' => [$this->teamB->id, $this->teamY->id, $this->teamX->id]])
        ->assertSessionHasErrors(['team_ids' => 'Embassy Portal belongs to another organization.']);
    $this->patch("/workspace/members/{$this->person->id}", ['managed_project_ids' => [$this->projectX->id]])
        ->assertSessionHasErrors(['managed_project_ids' => 'Embassy Portal belongs to another organization.']);
    $this->patch("/workspace/members/{$this->person->id}", ['managed_program_ids' => [$this->foreignProgram->id]])
        ->assertSessionHasErrors(['managed_program_ids' => 'The Foreign Affairs program belongs to another organization.']);

    expect(memberRolesTeamIds($this->person))->toBe(memberRolesSorted($this->teamB->id, $this->teamY->id))
        ->and(memberRolesLeaderIds($this->teamX))->toBe([$this->managerX->id]);
});

it('checks roles against the organization a super-admin moves the person to', function () {
    $mover = Member::factory()->create(['organization_id' => $this->org->id]);
    $stayer = Member::factory()->create(['organization_id' => $this->org->id]);
    $this->actingAs(memberRolesBoss());

    $this->patch("/workspace/members/{$mover->id}", [
        'organization_id' => $this->otherOrg->id,
        'team_ids' => [$this->teamX->id],
        'managed_program_ids' => [$this->foreignProgram->id],
    ])->assertSessionHasNoErrors();

    expect($mover->fresh()->organization_id)->toBe($this->otherOrg->id)
        ->and(memberRolesTeamIds($mover))->toBe([$this->teamX->id])
        ->and($this->foreignProgram->fresh()->manager_id)->toBe($mover->id);

    $this->patch("/workspace/members/{$stayer->id}", [
        'organization_id' => $this->otherOrg->id,
        'team_ids' => [$this->teamA->id],
    ])->assertSessionHasErrors(['team_ids' => 'Tax Portal (Revenue) belongs to another organization.']);

    expect($stayer->fresh()->organization_id)->toBe($this->org->id)
        ->and($stayer->teams()->exists())->toBeFalse();
});

it('never leaves a team without its manager when a super-admin moves them', function () {
    $this->actingAs(memberRolesBoss())
        ->patch("/workspace/members/{$this->underSecretary->id}", [
            'organization_id' => $this->otherOrg->id,
            'team_ids' => [],
        ])
        ->assertSessionHasErrors(['organization_id' => 'Choose another manager for Tax Portal (Revenue) first.']);

    expect($this->underSecretary->fresh()->organization_id)->toBe($this->org->id)
        ->and(memberRolesLeaderIds($this->teamA))->toBe([$this->underSecretary->id]);
});

// ---- Adding a person ----

it('lets an organizational admin give a new person their roles', function () {
    $this->actingAs($this->admin)
        ->post('/workspace/members', [
            'name' => 'New Joint Secretary', 'email' => 'js@example.com', 'password' => 'Kp7m-Qx4r-Tz9a',
            'managed_program_ids' => [$this->program->id],
            'managed_project_ids' => [$this->projectA->id],
            'team_ids' => [$this->teamB->id],
        ])
        ->assertSessionHasNoErrors();

    $member = Member::query()->where('email', 'js@example.com')->firstOrFail();

    expect($member->organization_id)->toBe($this->org->id)
        ->and($this->program->fresh()->manager_id)->toBe($member->id)
        ->and(memberRolesLeaderIds($this->teamA))->toBe([$member->id])
        ->and(memberRolesRole($this->teamA, $this->underSecretary))->toBe('member')
        ->and(memberRolesTeamIds($member))->toBe(memberRolesSorted($this->teamA->id, $this->teamB->id));
});

it('adds nobody when a new person\'s roles reach another organization', function () {
    $this->actingAs($this->admin);

    $this->post('/workspace/members', [
        'name' => 'Reacher', 'email' => 'reach@example.com', 'password' => 'Kp7m-Qx4r-Tz9a',
        'managed_program_ids' => [$this->foreignProgram->id],
    ])->assertSessionHasErrors(['managed_program_ids' => 'The Foreign Affairs program belongs to another organization.']);
    $this->post('/workspace/members', [
        'name' => 'Reacher', 'email' => 'reach@example.com', 'password' => 'Kp7m-Qx4r-Tz9a',
        'managed_project_ids' => [$this->projectY->id],
    ])->assertSessionHasErrors(['managed_project_ids' => 'Joint Desk belongs to another organization.']);
    $this->post('/workspace/members', [
        'name' => 'Reacher', 'email' => 'reach@example.com', 'password' => 'Kp7m-Qx4r-Tz9a',
        'team_ids' => [$this->teamY->id],
    ])->assertSessionHasErrors(['team_ids' => 'Joint Desk belongs to another organization.']);

    expect(Member::query()->where('email', 'reach@example.com')->exists())->toBeFalse()
        ->and(memberRolesLeaderIds($this->teamY))->toBe([$this->managerY->id]);
    Mail::assertNothingSent();
});

// ---- Nothing else changes ----

it('changes no roles for a save without role fields', function () {
    $this->actingAs($this->admin)
        ->patch("/workspace/members/{$this->underSecretary->id}", ['name' => 'Renamed'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash.message', 'Member updated.');
    $this->actingAs($this->admin)
        ->patch("/workspace/members/{$this->jointSecretary->id}", ['title' => 'Joint Secretary'])
        ->assertSessionHasNoErrors();

    expect(memberRolesLeaderIds($this->teamA))->toBe([$this->underSecretary->id])
        ->and(memberRolesTeamIds($this->underSecretary))->toBe([$this->teamA->id])
        ->and($this->program->fresh()->manager_id)->toBe($this->jointSecretary->id);
    Notification::assertNothingSent();
});

// ---- The People page ----

it('gives the People page the role options of the organizations the viewer runs', function () {
    $props = $this->actingAs($this->admin)->get('/workspace/team')->assertOk()->viewData('page')['props'];

    expect($props['canSetRoles'])->toBeTrue()
        ->and($props['roleOptions']['programs'])->toEqual([[
            'id' => $this->program->id,
            'name' => 'Revenue',
            'organization_id' => $this->org->id,
            'manager' => ['id' => $this->jointSecretary->id, 'name' => $this->jointSecretary->name],
        ]])
        ->and($props['roleOptions']['projects'])->toEqual([
            [
                'id' => $this->projectB->id,
                'title' => 'Customs',
                'program' => null,
                'organization_id' => $this->org->id,
                'team_id' => $this->teamB->id,
                'manager' => ['id' => $this->managerB->id, 'name' => $this->managerB->name],
            ],
            [
                'id' => $this->projectA->id,
                'title' => 'Tax Portal',
                'program' => 'Revenue',
                'organization_id' => $this->org->id,
                'team_id' => $this->teamA->id,
                'manager' => ['id' => $this->underSecretary->id, 'name' => $this->underSecretary->name],
            ],
        ]);

    $rows = collect($props['members'])->keyBy('id');

    expect($rows[$this->jointSecretary->id]['managed_program_ids'])->toBe([$this->program->id])
        ->and($rows[$this->underSecretary->id]['managed_project_ids'])->toBe([$this->projectA->id])
        ->and($rows[$this->person->id]['managed_project_ids'])->toBe([])
        ->and($rows[$this->person->id]['managed_program_ids'])->toBe([]);

    $boss = $this->actingAs(memberRolesBoss())->get('/workspace/team')->assertOk()->viewData('page')['props'];

    expect($boss['canSetRoles'])->toBeTrue()
        ->and(collect($boss['roleOptions']['programs'])->pluck('name')->all())->toBe(['Foreign Affairs', 'Revenue'])
        ->and(collect($boss['roleOptions']['projects'])->pluck('title')->all())->toBe(['Customs', 'Embassy Portal', 'Joint Desk', 'Tax Portal']);

    $member = $this->actingAs($this->officer->user)->get('/workspace/team')->assertOk()->viewData('page')['props'];

    expect($member['canSetRoles'])->toBeFalse()
        ->and($member['roleOptions'])->toEqual(['programs' => [], 'projects' => []]);
});

it('names each project with its program, so two General projects can be told apart', function () {
    $customs = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->managerB->id, 'name' => 'Customs Reform']);
    $generalR = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $this->program->id, 'title' => 'General']);
    $generalR->ensureTeam()->members()->attach($this->jointSecretary->id, ['role' => 'leader']);
    $generalC = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $customs->id, 'title' => 'General']);
    $generalC->ensureTeam()->members()->attach($this->managerB->id, ['role' => 'leader']);

    expect($generalR->label())->toBe('General (Revenue)')
        ->and($this->projectB->label())->toBe('Customs');

    $props = $this->actingAs($this->admin)->get('/workspace/team')->assertOk()->viewData('page')['props'];
    $options = collect($props['roleOptions']['projects'])->keyBy('id');
    $teams = collect($props['teams'])->keyBy('id');

    expect($options[$generalR->id]['program'])->toBe('Revenue')
        ->and($options[$generalC->id]['program'])->toBe('Customs Reform')
        ->and($options[$this->projectB->id]['program'])->toBeNull()
        // The rows read "Manages General (Revenue)".
        ->and($teams[$generalR->team()->id]['project']['program'])->toBe('Revenue')
        ->and($teams[$this->teamB->id]['project']['program'])->toBeNull();

    $this->patch("/workspace/members/{$this->jointSecretary->id}", ['managed_project_ids' => []])
        ->assertSessionHasErrors(['managed_project_ids' => 'Choose another manager for General (Revenue) first.']);

    expect(memberRolesLeaderIds($generalR->team()))->toBe([$this->jointSecretary->id]);
});

it('lets whoever administers an organization set roles in it, and nobody else', function () {
    $boss = memberRolesBoss();
    $deactivated = User::factory()->create();
    Member::factory()->linkedTo($deactivated)->inactive()->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);

    expect(WorkspaceAccess::canSetRolesIn($boss, null))->toBeTrue()
        ->and(WorkspaceAccess::canSetRolesIn($boss, $this->otherOrg->id))->toBeTrue()
        ->and(WorkspaceAccess::canSetRolesIn($this->admin, $this->org->id))->toBeTrue()
        ->and(WorkspaceAccess::canSetRolesIn($this->admin, $this->otherOrg->id))->toBeFalse()
        ->and(WorkspaceAccess::canSetRolesIn($this->admin, null))->toBeFalse()
        ->and(WorkspaceAccess::canSetRolesIn($deactivated, $this->org->id))->toBeFalse()
        ->and(WorkspaceAccess::canSetRolesIn($this->jointSecretary->user, $this->org->id))->toBeFalse()
        ->and(WorkspaceAccess::canSetRolesIn($this->underSecretary->user, $this->org->id))->toBeFalse()
        ->and(WorkspaceAccess::canSetRolesIn(null, $this->org->id))->toBeFalse();

    // A deactivated organizational admin gets no role fields.
    $props = $this->actingAs($deactivated)->get('/workspace/team')->assertOk()->viewData('page')['props'];

    expect($props['canSetRoles'])->toBeFalse()
        ->and($props['roleOptions'])->toEqual(['programs' => [], 'projects' => []]);
});

it('leaves archived projects out of the role options', function () {
    $this->projectB->archive();

    $props = $this->actingAs($this->admin)->get('/workspace/team')->assertOk()->viewData('page')['props'];

    expect(collect($props['roleOptions']['projects'])->pluck('title')->all())->toBe(['Tax Portal']);
});

it('loads the People page roles without a query per person', function () {
    $this->actingAs($this->admin);
    $count = function (): int {
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });
        $this->get('/workspace/team')->assertOk();

        return $queries;
    };

    $before = $count();

    foreach (range(1, 4) as $i) {
        $extra = Member::factory()->create(['organization_id' => $this->org->id]);
        $this->teamB->members()->attach($extra->id, ['role' => 'member']);
    }
    Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $extra->id, 'name' => 'Customs Reform']);

    expect($count())->toBe($before);
});
