<?php

use App\Jobs\SyncRepositoryAccess;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\GitRepositoryGrant;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
 * The JSON twin of RepositoryGrantTest: the same people and the same
 * matrix, through Sanctum tokens.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com'], 'queue.default' => 'database']);
    Queue::fake();
    Http::preventStrayRequests();

    $this->organization = Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
    $this->connection = GitConnection::query()->create([
        'organization_id' => $this->organization->id,
        'base_url' => 'https://git.example',
        'owner' => 'sdoc',
        'token' => 'bot-token-never-shown',
        'webhook_id' => 99,
        'webhook_secret' => 'hook-secret-never-shown',
    ]);

    [$this->programManagerUser, $this->programManager] = grantApiPerson($this->organization, 'Pradeep Program', gitUsername: null, title: 'Engineering head');
    $this->program = Program::factory()->create(['organization_id' => $this->organization->id, 'manager_id' => $this->programManager->id, 'name' => 'Revenue']);
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'program_id' => $this->program->id, 'title' => 'Tax Portal', 'key' => 'TP']);
    $this->team = $this->project->ensureTeam();

    [$this->managerUser, $this->manager] = grantApiPerson($this->organization, 'Mira Manager', gitUsername: 'mira', title: 'PM');
    [$this->teamMemberUser, $this->teamMember] = grantApiPerson($this->organization, 'Tara Teammember');
    [, $this->asha] = grantApiPerson($this->organization, 'Asha Teammate', gitUsername: 'asha', title: 'Senior developer');
    [$this->adminUser] = grantApiPerson($this->organization, 'Kamala Admin', admin: true);
    $this->bina = Member::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Bina Colleague', 'email' => 'bina@sdoc.example', 'title' => 'Analyst']);

    $this->team->members()->attach($this->manager->id, ['role' => 'leader']);
    $this->team->members()->attach($this->teamMember->id, ['role' => 'member']);
    $this->team->members()->attach($this->asha->id, ['role' => 'member']);

    $this->api = grantApiLinked($this->project, $this->connection, 'tp-api', 501);
    $this->web = grantApiLinked($this->project, $this->connection, 'tp-web', 502);
    $this->base = "/api/v1/workspace/projects/{$this->project->slug}/repositories/{$this->api->id}/access";
});

/** @return array{0: User, 1: Member} */
function grantApiPerson(Organization $organization, string $name, ?string $gitUsername = null, ?string $title = null, bool $admin = false): array
{
    $user = User::factory()->create(['name' => $name, 'git_username' => $gitUsername]);
    $member = Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id, 'is_office_admin' => $admin, 'title' => $title]);

    return [$user, $member];
}

function grantApiLinked(Project $project, GitConnection $connection, string $name, int $forgeId): GitRepository
{
    return GitRepository::query()->create([
        'git_connection_id' => $connection->id,
        'project_id' => $project->id,
        'forge_id' => $forgeId,
        'owner' => 'sdoc',
        'name' => $name,
        'full_name' => "sdoc/{$name}",
        'html_url' => "https://git.example/sdoc/{$name}",
        'clone_url' => "https://git.example/sdoc/{$name}.git",
        'ssh_url' => "git@git.example:sdoc/{$name}.git",
        'default_branch' => 'main',
    ]);
}

function grantApiGrant(GitRepository $repository, Member $member, string $permission = GitRepositoryGrant::WRITE, ?User $by = null): GitRepositoryGrant
{
    return GitRepositoryGrant::query()->create([
        'git_repository_id' => $repository->id,
        'member_id' => $member->id,
        'permission' => $permission,
        'granted_by' => $by?->id,
    ]);
}

/**
 * The ids of the people the user may give access to the repository who
 * have no grant on it yet: the rule, applied to everyone.
 *
 * @return list<int>
 */
function grantApiRuleSet(User $user, GitRepository $repository): array
{
    WorkspaceAccess::flush();
    $project = $repository->project()->sole();
    $granted = $repository->grants()->pluck('member_id')->all();

    $ids = Member::query()->get()
        ->filter(fn (Member $member): bool => WorkspaceAccess::canGrantRepositoryAccessTo($user, $project, $member) && ! in_array($member->id, $granted, true))
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();
    sort($ids);

    return array_values($ids);
}

/**
 * Every key the rows carry, sorted.
 *
 * @param  list<array<string, mixed>>  $rows
 * @return list<string>
 */
function grantApiKeys(array $rows): array
{
    return collect($rows)->flatMap(fn (array $row): array => array_keys($row))->unique()->sort()->values()->all();
}

// Happy paths

it('lets an organizational admin give someone of the organization who is not on the team write access', function () {
    Sanctum::actingAs($this->adminUser, ['*']);

    $response = $this->postJson($this->base, ['member_id' => $this->bina->id, 'permission' => 'write'])
        ->assertCreated()
        ->assertJsonPath('message', 'Gave Bina Colleague write access to sdoc/tp-api.');

    $grant = GitRepositoryGrant::query()->sole();
    expect($response->json('data'))->toEqual([
        'id' => $grant->id,
        'permission' => 'write',
        'waiting' => true,
        'member' => ['id' => $this->bina->id, 'name' => 'Bina Colleague', 'title' => 'Analyst'],
        'granted_at' => $grant->created_at->toIso8601String(),
    ])
        ->and($grant->granted_by)->toBe($this->adminUser->id);
    Queue::assertPushed(SyncRepositoryAccess::class, fn (SyncRepositoryAccess $job): bool => $job->repository->is($this->api));
});

it('lets the project\'s manager give a teammate read access', function () {
    Sanctum::actingAs($this->managerUser, ['*']);

    $this->postJson($this->base, ['member_id' => $this->asha->id, 'permission' => 'read'])
        ->assertCreated()
        ->assertJsonPath('message', 'Gave Asha Teammate read access to sdoc/tp-api.')
        ->assertJsonPath('data.permission', 'read')
        ->assertJsonPath('data.waiting', false);

    expect(GitRepositoryGrant::query()->sole()->only(['member_id', 'permission', 'granted_by']))
        ->toEqual(['member_id' => $this->asha->id, 'permission' => 'read', 'granted_by' => $this->managerUser->id]);
});

it('changes a grant\'s permission', function () {
    $grant = grantApiGrant($this->api, $this->asha, by: $this->adminUser);
    Sanctum::actingAs($this->managerUser, ['*']);

    $this->patchJson("{$this->base}/{$grant->id}", ['permission' => 'read'])
        ->assertOk()
        ->assertJsonPath('message', 'Asha Teammate now has read access to sdoc/tp-api.')
        ->assertJsonPath('data.id', $grant->id)
        ->assertJsonPath('data.permission', 'read');

    expect($grant->fresh()->permission)->toBe('read');
});

it('removes a grant', function () {
    $grant = grantApiGrant($this->api, $this->asha);
    Sanctum::actingAs($this->managerUser, ['*']);

    $this->deleteJson("{$this->base}/{$grant->id}")
        ->assertOk()
        ->assertJsonPath('message', "Removed Asha Teammate's access to sdoc/tp-api.");

    expect(GitRepositoryGrant::query()->count())->toBe(0);
});

it('lists the maintainers with their role and the grants by name, each saying whether they wait for a first sign-in', function () {
    $bina = grantApiGrant($this->api, $this->bina, GitRepositoryGrant::READ);
    $asha = grantApiGrant($this->api, $this->asha);
    grantApiGrant($this->web, $this->teamMember);
    Sanctum::actingAs($this->adminUser, ['*']);

    $response = $this->getJson($this->base)->assertOk();

    expect($response->json('data.maintainers'))->toEqual([
        ['id' => $this->manager->id, 'name' => 'Mira Manager', 'title' => 'PM', 'role' => 'manager', 'waiting' => false],
        ['id' => $this->programManager->id, 'name' => 'Pradeep Program', 'title' => 'Engineering head', 'role' => 'program manager', 'waiting' => true],
    ])
        ->and($response->json('data.grants'))->toEqual([
            ['id' => $asha->id, 'permission' => 'write', 'waiting' => false, 'member' => ['id' => $this->asha->id, 'name' => 'Asha Teammate', 'title' => 'Senior developer'], 'granted_at' => $asha->created_at->toIso8601String()],
            ['id' => $bina->id, 'permission' => 'read', 'waiting' => true, 'member' => ['id' => $this->bina->id, 'name' => 'Bina Colleague', 'title' => 'Analyst'], 'granted_at' => $bina->created_at->toIso8601String()],
        ]);
});

// Failures

it('refuses a permission other than read or write', function (string $permission) {
    $grant = grantApiGrant($this->api, $this->asha);
    Sanctum::actingAs($this->adminUser, ['*']);

    $this->postJson($this->base, ['member_id' => $this->bina->id, 'permission' => $permission])->assertUnprocessable()->assertJsonValidationErrors('permission');
    $this->patchJson("{$this->base}/{$grant->id}", ['permission' => $permission])->assertUnprocessable()->assertJsonValidationErrors('permission');

    expect(GitRepositoryGrant::query()->sole()->permission)->toBe('write');
})->with(['admin', 'owner', '']);

it('refuses someone the tracker doesn\'t have', function () {
    Sanctum::actingAs($this->adminUser, ['*']);

    $this->postJson($this->base, ['member_id' => 999999, 'permission' => 'read'])->assertUnprocessable()->assertJsonValidationErrors('member_id');

    expect(GitRepositoryGrant::query()->count())->toBe(0);
});

// Privilege escalation

it('refuses a project manager giving access to someone of the organization who is not on the team', function () {
    Sanctum::actingAs($this->managerUser, ['*']);

    $this->postJson($this->base, ['member_id' => $this->bina->id, 'permission' => 'write'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['member_id' => "You can't give Bina Colleague access to this repository."]);

    expect(GitRepositoryGrant::query()->count())->toBe(0);
    Queue::assertNotPushed(SyncRepositoryAccess::class);
});

it('refuses a team member and another organization\'s organizational admin on every route', function (string $who) {
    $user = $who === 'team member'
        ? $this->teamMemberUser
        : grantApiPerson(Organization::factory()->create(), 'Olga Outside', admin: true)[0];
    $grant = grantApiGrant($this->api, $this->asha);
    Sanctum::actingAs($user, ['*']);

    $this->getJson($this->base)->assertForbidden();
    $this->getJson("{$this->base}/candidates?q=a")->assertForbidden();
    $this->postJson($this->base, ['member_id' => $this->bina->id, 'permission' => 'write'])->assertForbidden();
    $this->patchJson("{$this->base}/{$grant->id}", ['permission' => 'read'])->assertForbidden();
    $this->deleteJson("{$this->base}/{$grant->id}")->assertForbidden();

    expect(GitRepositoryGrant::query()->sole()->permission)->toBe('write');
})->with(['team member', 'outside admin']);

it('finds a grant only under its own repository', function () {
    $elsewhere = grantApiGrant($this->web, $this->asha);
    Sanctum::actingAs($this->adminUser, ['*']);

    $this->patchJson("{$this->base}/{$elsewhere->id}", ['permission' => 'read'])->assertNotFound();
    $this->deleteJson("{$this->base}/{$elsewhere->id}")->assertNotFound();

    expect($elsewhere->fresh()->permission)->toBe('write');
});

it('lets a project manager remove, but not raise, an organizational admin\'s grant to someone off the team', function () {
    $grant = grantApiGrant($this->api, $this->bina, GitRepositoryGrant::READ, $this->adminUser);
    Sanctum::actingAs($this->managerUser, ['*']);

    $this->patchJson("{$this->base}/{$grant->id}", ['permission' => 'write'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['permission' => "You can't give Bina Colleague access to this repository."]);
    expect($grant->fresh()->permission)->toBe('read');

    $this->deleteJson("{$this->base}/{$grant->id}")
        ->assertOk()
        ->assertJsonPath('message', "Removed Bina Colleague's access to sdoc/tp-api.");
    expect(GitRepositoryGrant::query()->count())->toBe(0);
});

// Candidates

it('offers a project manager exactly the people the rule lets them give access to, without emails', function () {
    grantApiGrant($this->api, $this->asha);
    Sanctum::actingAs($this->managerUser, ['*']);

    $data = $this->getJson("{$this->base}/candidates")->assertOk()->json('data');
    $ids = collect($data)->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(grantApiRuleSet($this->managerUser, $this->api))
        ->and($ids)->toBe([$this->teamMember->id])
        ->and(grantApiKeys($data))->toBe(['id', 'name', 'title']);
});

it('offers an organizational admin exactly the people the rule lets them give access to, without emails', function () {
    grantApiGrant($this->api, $this->asha);
    Member::factory()->inactive()->create(['organization_id' => $this->organization->id]);
    Member::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    Sanctum::actingAs($this->adminUser, ['*']);

    $data = $this->getJson("{$this->base}/candidates")->assertOk()->json('data');
    $ids = collect($data)->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(grantApiRuleSet($this->adminUser, $this->api))
        ->and($ids)->toContain($this->bina->id)
        ->and(grantApiKeys($data))->toBe(['id', 'name', 'title']);
});
