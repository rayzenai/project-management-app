<?php

use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\GitRepositoryGrant;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Queries\RepositoryAccessCandidatesQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Database\UniqueConstraintViolationException;

/*
 * Who may give people access to a linked repository, and to whom.
 *
 * Organization SDOC has git. Project Tax Portal is in the program Revenue:
 * its manager leads its team, the joint secretary manages the program. The
 * grantees: a teammate (on the team), a colleague (SDOC, not on the team),
 * an outsider (of another organization, on the team once it is opened to
 * other organizations), a stranger (another organization, not on the team),
 * an inactive teammate, and the super-admin's own member (no organization)
 * on the team.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    WorkspaceAccess::flush();

    $this->organization = Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
    $this->otherOrganization = Organization::factory()->create(['name' => 'Elsewhere']);
    $this->connection = GitConnection::query()->create([
        'organization_id' => $this->organization->id,
        'base_url' => 'https://git.example',
        'owner' => 'sdoc',
        'token' => 'bot-token-never-shown',
        'webhook_id' => 99,
        'webhook_secret' => 'hook-secret-never-shown',
    ]);

    $this->managerUser = User::factory()->create();
    $this->manager = Member::factory()->linkedTo($this->managerUser)->create(['organization_id' => $this->organization->id]);

    $this->programManagerUser = User::factory()->create();
    $this->programManager = Member::factory()->linkedTo($this->programManagerUser)->create(['organization_id' => $this->organization->id]);
    $this->program = Program::factory()->create(['organization_id' => $this->organization->id, 'manager_id' => $this->programManager->id, 'name' => 'Revenue']);

    $this->adminUser = User::factory()->create();
    $this->admin = Member::factory()->linkedTo($this->adminUser)->create(['organization_id' => $this->organization->id, 'is_office_admin' => true]);

    $this->superAdminUser = User::factory()->create(['email' => 'boss@example.com']);
    $this->superAdminMember = Member::factory()->linkedTo($this->superAdminUser)->create(['organization_id' => null]);

    $this->outsideAdminUser = User::factory()->create();
    Member::factory()->linkedTo($this->outsideAdminUser)->create(['organization_id' => $this->otherOrganization->id, 'is_office_admin' => true]);

    $this->teamMemberUser = User::factory()->create();
    $this->teamMember = Member::factory()->linkedTo($this->teamMemberUser)->create(['organization_id' => $this->organization->id]);

    $this->project = Project::factory()->create([
        'organization_id' => $this->organization->id,
        'program_id' => $this->program->id,
        'title' => 'Tax Portal',
        'key' => 'TP',
    ]);
    $this->team = $this->project->ensureTeam();

    $this->teammate = Member::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Asha Teammate', 'email' => 'asha@sdoc.example']);
    $this->colleague = Member::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Bina Colleague', 'email' => 'bina@sdoc.example']);
    $this->outsider = Member::factory()->create(['organization_id' => $this->otherOrganization->id, 'name' => 'Chandra Outsider']);
    $this->stranger = Member::factory()->create(['organization_id' => $this->otherOrganization->id, 'name' => 'Dipak Stranger']);
    $this->inactive = Member::factory()->inactive()->create(['organization_id' => $this->organization->id, 'name' => 'Era Inactive']);

    $this->team->members()->attach($this->manager->id, ['role' => 'leader']);
    $this->team->members()->attach($this->teamMember->id, ['role' => 'member']);
    $this->team->members()->attach($this->teammate->id, ['role' => 'member']);
    $this->team->members()->attach($this->inactive->id, ['role' => 'member']);
    $this->team->members()->attach($this->superAdminMember->id, ['role' => 'member']);

    $this->repository = GitRepository::query()->create([
        'git_connection_id' => $this->connection->id,
        'project_id' => $this->project->id,
        'forge_id' => 501,
        'owner' => 'sdoc',
        'name' => 'tp-api',
        'full_name' => 'sdoc/tp-api',
        'html_url' => 'https://git.example/sdoc/tp-api',
        'clone_url' => 'https://git.example/sdoc/tp-api.git',
        'ssh_url' => 'git@git.example:sdoc/tp-api.git',
        'default_branch' => 'main',
    ]);
});

/**
 * Opens the project's team to other organizations and puts the outsider on it.
 */
function grantRulesOpenTeam(object $test): void
{
    $test->team->update(['is_cross_organization' => true]);
    $test->team->members()->attach($test->outsider->id, ['role' => 'member']);
    $test->project = $test->project->fresh();
}

/**
 * @return list<int>
 */
function grantRulesSorted(iterable $ids): array
{
    $ids = array_map(fn ($id): int => (int) $id, [...$ids]);
    sort($ids);

    return $ids;
}

it('lets whoever runs the project manage a repository\'s access, and nobody else', function () {
    foreach ([$this->managerUser, $this->programManagerUser, $this->adminUser, $this->superAdminUser] as $user) {
        expect(WorkspaceAccess::canManageRepositoryAccess($user, $this->repository))->toBeTrue();
    }

    foreach ([$this->teamMemberUser, $this->outsideAdminUser, null] as $user) {
        expect(WorkspaceAccess::canManageRepositoryAccess($user, $this->repository))->toBeFalse();
    }
});

it('lets nobody manage a repository\'s access once the organization has no connection', function () {
    $this->connection->delete();
    WorkspaceAccess::flush();

    foreach ([$this->managerUser, $this->programManagerUser, $this->adminUser, $this->superAdminUser] as $user) {
        expect(WorkspaceAccess::canManageRepositoryAccess($user, $this->repository))->toBeFalse()
            ->and(WorkspaceAccess::canGrantRepositoryAccessTo($user, $this->project, $this->teammate))->toBeFalse();
    }
});

it('lets an organizational admin grant anyone active in the organization or on the team', function () {
    grantRulesOpenTeam($this);

    expect(WorkspaceAccess::canGrantRepositoryAccessTo($this->adminUser, $this->project, $this->colleague))->toBeTrue()
        ->and(WorkspaceAccess::canGrantRepositoryAccessTo($this->adminUser, $this->project, $this->teammate))->toBeTrue()
        ->and(WorkspaceAccess::canGrantRepositoryAccessTo($this->adminUser, $this->project, $this->outsider))->toBeTrue();
});

it('lets a super-admin grant anyone active in the organization or on the team', function () {
    grantRulesOpenTeam($this);

    expect(WorkspaceAccess::canGrantRepositoryAccessTo($this->superAdminUser, $this->project, $this->colleague))->toBeTrue()
        ->and(WorkspaceAccess::canGrantRepositoryAccessTo($this->superAdminUser, $this->project, $this->teammate))->toBeTrue()
        ->and(WorkspaceAccess::canGrantRepositoryAccessTo($this->superAdminUser, $this->project, $this->outsider))->toBeTrue();
});

it('lets a project manager grant only people on the project\'s team', function () {
    expect(WorkspaceAccess::canGrantRepositoryAccessTo($this->managerUser, $this->project, $this->teammate))->toBeTrue()
        ->and(WorkspaceAccess::canGrantRepositoryAccessTo($this->managerUser, $this->project, $this->colleague))->toBeFalse();
});

it('lets a program manager grant only people on the project\'s team', function () {
    expect(WorkspaceAccess::canGrantRepositoryAccessTo($this->programManagerUser, $this->project, $this->teammate))->toBeTrue()
        ->and(WorkspaceAccess::canGrantRepositoryAccessTo($this->programManagerUser, $this->project, $this->colleague))->toBeFalse();
});

it('lets nobody grant an inactive person, a person with no organization, or a maintainer', function () {
    $inactiveColleague = Member::factory()->inactive()->create(['organization_id' => $this->organization->id]);

    foreach ([$this->managerUser, $this->programManagerUser, $this->adminUser, $this->superAdminUser] as $user) {
        foreach ([$this->inactive, $inactiveColleague, $this->superAdminMember, $this->manager, $this->programManager] as $member) {
            expect(WorkspaceAccess::canGrantRepositoryAccessTo($user, $this->project, $member))->toBeFalse();
        }
    }
});

it('lets nobody grant someone of another organization who is not on the team', function () {
    // Opened to other organizations, the team still does not hold the stranger.
    grantRulesOpenTeam($this);

    foreach ([$this->managerUser, $this->programManagerUser, $this->adminUser, $this->superAdminUser] as $user) {
        expect(WorkspaceAccess::canGrantRepositoryAccessTo($user, $this->project, $this->stranger))->toBeFalse();
    }
});

it('lets a project manager grant a cross-organization team\'s outsider', function () {
    expect(WorkspaceAccess::canGrantRepositoryAccessTo($this->managerUser, $this->project, $this->outsider))->toBeFalse();

    grantRulesOpenTeam($this);

    expect(WorkspaceAccess::canGrantRepositoryAccessTo($this->managerUser, $this->project, $this->outsider))->toBeTrue()
        ->and(WorkspaceAccess::canGrantRepositoryAccessTo($this->programManagerUser, $this->project, $this->outsider))->toBeTrue();
});

it('lets people who may not manage the repository\'s access grant nobody', function () {
    grantRulesOpenTeam($this);

    foreach ([$this->teamMemberUser, $this->outsideAdminUser, null] as $user) {
        foreach ([$this->teammate, $this->colleague, $this->outsider] as $member) {
            expect(WorkspaceAccess::canGrantRepositoryAccessTo($user, $this->project, $member))->toBeFalse();
        }
    }
});

it('takes repository access away from a deactivated :dataset', function (string $user, string $member) {
    // Deactivated people can still sign in: the power must go with the flag.
    $this->{$member}->update(['is_active' => false]);
    WorkspaceAccess::flush();
    $project = $this->project->fresh();

    expect(WorkspaceAccess::canManageRepositoryAccess($this->{$user}, $this->repository->fresh()))->toBeFalse()
        ->and(app(RepositoryAccessCandidatesQuery::class)->get($this->{$user}, $this->repository->fresh(), ''))->toBe([]);

    foreach ([$this->teammate, $this->colleague] as $grantee) {
        expect(WorkspaceAccess::canGrantRepositoryAccessTo($this->{$user}, $project, $grantee))->toBeFalse();
    }
})->with([
    'project manager' => ['managerUser', 'manager'],
    'program manager' => ['programManagerUser', 'programManager'],
    'organizational admin' => ['adminUser', 'admin'],
]);

it('lets nobody grant a deleted person', function () {
    $this->colleague->delete();
    $this->teammate->delete();

    expect(WorkspaceAccess::canGrantRepositoryAccessTo($this->adminUser, $this->project, $this->colleague))->toBeFalse()
        ->and(WorkspaceAccess::canGrantRepositoryAccessTo($this->managerUser, $this->project, $this->teammate))->toBeFalse();
});

it('makes the manager and the program\'s manager the maintainers', function () {
    expect(grantRulesSorted(WorkspaceAccess::repositoryMaintainerIds($this->project)))
        ->toBe(grantRulesSorted([$this->manager->id, $this->programManager->id]));
});

it('leaves an inactive program manager out of the maintainers', function () {
    $this->programManager->update(['is_active' => false]);

    expect(WorkspaceAccess::repositoryMaintainerIds($this->project->fresh()))->toBe([$this->manager->id]);
});

it('leaves an inactive project manager out of the maintainers', function () {
    $this->manager->update(['is_active' => false]);

    expect(WorkspaceAccess::repositoryMaintainerIds($this->project->fresh()))->toBe([$this->programManager->id]);
});

it('leaves out the manager of a program in another organization', function () {
    // Bad data: the project pointed at another organization's program.
    $foreignManager = Member::factory()->create(['organization_id' => $this->otherOrganization->id]);
    $foreignProgram = Program::factory()->create(['organization_id' => $this->otherOrganization->id, 'manager_id' => $foreignManager->id]);
    $this->project->update(['program_id' => $foreignProgram->id]);

    expect(WorkspaceAccess::repositoryMaintainerIds($this->project->fresh()))->toBe([$this->manager->id]);
});

it('leaves out a program manager who moved to another organization', function () {
    $this->programManager->update(['organization_id' => $this->otherOrganization->id]);

    expect(WorkspaceAccess::repositoryMaintainerIds($this->project->fresh()))->toBe([$this->manager->id]);
});

it('names a maintainer once when they manage both the project and its program', function () {
    $this->program->update(['manager_id' => $this->manager->id]);

    expect(WorkspaceAccess::repositoryMaintainerIds($this->project->fresh()))->toBe([$this->manager->id]);
});

it('has no maintainers for a project with no manager and no program', function () {
    $bare = Project::factory()->create(['organization_id' => $this->organization->id]);

    expect(WorkspaceAccess::repositoryMaintainerIds($bare))->toBe([]);
});

it('drops grants of people who left the organization or were deactivated', function () {
    $deactivated = Member::factory()->create(['organization_id' => $this->organization->id]);
    $mover = Member::factory()->create(['organization_id' => $this->organization->id]);

    $kept = $this->repository->grants()->create(['member_id' => $this->colleague->id, 'permission' => GitRepositoryGrant::WRITE]);
    $this->repository->grants()->create(['member_id' => $deactivated->id, 'permission' => GitRepositoryGrant::READ]);
    $this->repository->grants()->create(['member_id' => $mover->id, 'permission' => GitRepositoryGrant::WRITE]);

    $deactivated->update(['is_active' => false]);
    $mover->update(['organization_id' => $this->otherOrganization->id]);

    expect($this->repository->grants()->effective()->pluck('id')->all())->toBe([$kept->id])
        ->and(GitRepositoryGrant::query()->count())->toBe(3);
});

it('keeps the grants of a cross-organization team\'s outsiders, on that project\'s repositories only', function () {
    grantRulesOpenTeam($this);

    $otherProject = Project::factory()->create(['organization_id' => $this->organization->id, 'key' => 'OP']);
    $otherProject->ensureTeam();
    $otherRepository = GitRepository::query()->create([
        'git_connection_id' => $this->connection->id,
        'project_id' => $otherProject->id,
        'forge_id' => 502,
        'owner' => 'sdoc',
        'name' => 'op-api',
        'full_name' => 'sdoc/op-api',
        'html_url' => 'https://git.example/sdoc/op-api',
        'clone_url' => 'https://git.example/sdoc/op-api.git',
        'ssh_url' => 'git@git.example:sdoc/op-api.git',
        'default_branch' => 'main',
    ]);

    $onTeam = $this->repository->grants()->create(['member_id' => $this->outsider->id, 'permission' => GitRepositoryGrant::READ]);
    $otherRepository->grants()->create(['member_id' => $this->outsider->id, 'permission' => GitRepositoryGrant::READ]);
    $this->repository->grants()->create(['member_id' => $this->stranger->id, 'permission' => GitRepositoryGrant::READ]);

    expect(GitRepositoryGrant::query()->effective()->pluck('id')->all())->toBe([$onTeam->id])
        ->and($this->outsider->repositoryGrants()->count())->toBe(2);
});

it('lists exactly the people canGrantRepositoryAccessTo allows, for each role', function () {
    grantRulesOpenTeam($this);
    $query = app(RepositoryAccessCandidatesQuery::class);

    foreach ([$this->managerUser, $this->programManagerUser, $this->adminUser, $this->superAdminUser, $this->teamMemberUser, $this->outsideAdminUser] as $user) {
        WorkspaceAccess::flush();
        $candidates = $query->get($user, $this->repository, '');

        $allowed = Member::query()->get()
            ->filter(fn (Member $member): bool => WorkspaceAccess::canGrantRepositoryAccessTo($user, $this->project, $member))
            ->pluck('id');

        expect(grantRulesSorted(array_column($candidates, 'id')))->toBe(grantRulesSorted($allowed));

        foreach ($candidates as $candidate) {
            expect($candidate)->toHaveKeys(['id', 'name', 'title'])->not->toHaveKey('email');
        }
    }

    // The fixture's people, as each role sees them.
    $ids = fn (User $user): array => grantRulesSorted(array_column($query->get($user, $this->repository, ''), 'id'));

    expect($ids($this->managerUser))->toBe(grantRulesSorted([$this->teamMember->id, $this->teammate->id, $this->outsider->id]))
        ->and($ids($this->adminUser))->toContain($this->colleague->id, $this->teammate->id, $this->outsider->id)
        ->and($ids($this->adminUser))->not->toContain($this->stranger->id, $this->inactive->id, $this->superAdminMember->id, $this->manager->id, $this->programManager->id)
        ->and($ids($this->teamMemberUser))->toBe([]);
});

it('finds candidates by name or email, ordered by name, and leaves out people already granted', function () {
    $query = app(RepositoryAccessCandidatesQuery::class);

    expect(array_column($query->get($this->adminUser, $this->repository, 'BINA@sdoc'), 'id'))->toBe([$this->colleague->id])
        ->and(array_column($query->get($this->adminUser, $this->repository, 'teammate'), 'id'))->toBe([$this->teammate->id]);

    $names = array_column($query->get($this->adminUser, $this->repository, ''), 'name');
    $sorted = $names;
    sort($sorted, SORT_STRING);
    expect($names)->toBe($sorted);

    $this->repository->grants()->create(['member_id' => $this->teammate->id, 'permission' => GitRepositoryGrant::READ]);

    expect(array_column($query->get($this->adminUser, $this->repository, 'teammate'), 'id'))->toBe([])
        ->and(array_column($query->get($this->managerUser, $this->repository, ''), 'id'))->not->toContain($this->teammate->id);
});

it('lists at most twenty candidates', function () {
    Member::factory()->count(25)->create(['organization_id' => $this->organization->id]);

    expect(app(RepositoryAccessCandidatesQuery::class)->get($this->adminUser, $this->repository, ''))->toHaveCount(20);
});

it('keeps one grant per person per repository, and repository defaults for access', function () {
    $this->repository->grants()->create(['member_id' => $this->teammate->id, 'permission' => GitRepositoryGrant::READ, 'granted_by' => $this->adminUser->id]);

    $repository = $this->repository->fresh();
    $grant = $repository->grants()->first();

    expect($repository->protects_main)->toBeFalse()
        ->and($repository->access_synced_at)->toBeNull()
        ->and($grant->member->is($this->teammate))->toBeTrue()
        ->and($grant->repository->is($repository))->toBeTrue()
        ->and($grant->grantedBy->is($this->adminUser))->toBeTrue()
        ->and($grant->getMorphClass())->toBe('git-repository-grant')
        ->and(GitRepositoryGrant::PERMISSIONS)->toBe(['read', 'write']);

    expect(fn () => $this->repository->grants()->create(['member_id' => $this->teammate->id, 'permission' => GitRepositoryGrant::WRITE]))
        ->toThrow(UniqueConstraintViolationException::class);
});
