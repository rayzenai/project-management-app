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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Giving people access to a linked repository from the web: list, search,
 * grant, change, remove. Who may do it is
 * WorkspaceAccess::canManageRepositoryAccess, to whom
 * canGrantRepositoryAccessTo. Syncs go to a faked worker queue, so nothing
 * reaches the forge.
 *
 * Tax Portal (SDOC, key TP) is in the program Revenue, managed by Pradeep;
 * Mira manages the project. Tara and Asha are on its team, Bina is in SDOC
 * but not on it, Kamala is SDOC's organizational admin. sdoc/tp-api and
 * sdoc/tp-web are linked to it.
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

    [$this->programManagerUser, $this->programManager] = grantWebPerson($this->organization, 'Pradeep Program', gitUsername: null, title: 'Engineering head');
    $this->program = Program::factory()->create(['organization_id' => $this->organization->id, 'manager_id' => $this->programManager->id, 'name' => 'Revenue']);
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'program_id' => $this->program->id, 'title' => 'Tax Portal', 'key' => 'TP']);
    $this->team = $this->project->ensureTeam();

    [$this->managerUser, $this->manager] = grantWebPerson($this->organization, 'Mira Manager', gitUsername: 'mira', title: 'PM');
    [$this->teamMemberUser, $this->teamMember] = grantWebPerson($this->organization, 'Tara Teammember');
    [, $this->asha] = grantWebPerson($this->organization, 'Asha Teammate', gitUsername: 'asha', title: 'Senior developer');
    [$this->adminUser] = grantWebPerson($this->organization, 'Kamala Admin', admin: true);
    $this->bina = Member::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Bina Colleague', 'email' => 'bina@sdoc.example']);

    $this->team->members()->attach($this->manager->id, ['role' => 'leader']);
    $this->team->members()->attach($this->teamMember->id, ['role' => 'member']);
    $this->team->members()->attach($this->asha->id, ['role' => 'member']);

    $this->api = grantWebLinked($this->project, $this->connection, 'tp-api', 501);
    $this->web = grantWebLinked($this->project, $this->connection, 'tp-web', 502);
    $this->base = "/workspace/projects/{$this->project->slug}/repositories/{$this->api->id}/access";
});

/** @return array{0: User, 1: Member} */
function grantWebPerson(Organization $organization, string $name, ?string $gitUsername = null, ?string $title = null, bool $admin = false): array
{
    $user = User::factory()->create(['name' => $name, 'git_username' => $gitUsername]);
    $member = Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id, 'is_office_admin' => $admin, 'title' => $title]);

    return [$user, $member];
}

function grantWebLinked(Project $project, GitConnection $connection, string $name, int $forgeId): GitRepository
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

function grantWebGrant(GitRepository $repository, Member $member, string $permission = GitRepositoryGrant::WRITE, ?User $by = null): GitRepositoryGrant
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
function grantWebRuleSet(User $user, GitRepository $repository): array
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
function grantWebKeys(array $rows): array
{
    return collect($rows)->flatMap(fn (array $row): array => array_keys($row))->unique()->sort()->values()->all();
}

// Happy paths

it('lets an organizational admin give someone of the organization who is not on the team write access', function () {
    $this->actingAs($this->adminUser)
        ->post($this->base, ['member_id' => $this->bina->id, 'permission' => 'write'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Gave Bina Colleague write access to sdoc/tp-api.']);

    $grant = GitRepositoryGrant::query()->sole();
    expect($grant->git_repository_id)->toBe($this->api->id)
        ->and($grant->member_id)->toBe($this->bina->id)
        ->and($grant->permission)->toBe('write')
        ->and($grant->granted_by)->toBe($this->adminUser->id);
    Queue::assertPushed(SyncRepositoryAccess::class, fn (SyncRepositoryAccess $job): bool => $job->repository->is($this->api));
});

it('lets the project\'s manager give a teammate read access', function () {
    $this->actingAs($this->managerUser)
        ->post($this->base, ['member_id' => $this->asha->id, 'permission' => 'read'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Gave Asha Teammate read access to sdoc/tp-api.']);

    expect(GitRepositoryGrant::query()->sole()->only(['member_id', 'permission', 'granted_by']))
        ->toEqual(['member_id' => $this->asha->id, 'permission' => 'read', 'granted_by' => $this->managerUser->id]);
});

it('changes a grant\'s permission', function () {
    $grant = grantWebGrant($this->api, $this->asha, by: $this->adminUser);

    $this->actingAs($this->managerUser)
        ->patch("{$this->base}/{$grant->id}", ['permission' => 'read'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Asha Teammate now has read access to sdoc/tp-api.']);

    expect($grant->fresh()->permission)->toBe('read')
        ->and(GitRepositoryGrant::query()->count())->toBe(1);
});

it('says so when a grant is given again at the same permission', function () {
    grantWebGrant($this->api, $this->asha);

    $this->actingAs($this->managerUser)
        ->post($this->base, ['member_id' => $this->asha->id, 'permission' => 'write'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash.message', 'Gave Asha Teammate write access to sdoc/tp-api.');

    expect(GitRepositoryGrant::query()->sole()->permission)->toBe('write');
});

it('removes a grant', function () {
    $grant = grantWebGrant($this->api, $this->asha);

    $this->actingAs($this->managerUser)
        ->delete("{$this->base}/{$grant->id}")
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => "Removed Asha Teammate's access to sdoc/tp-api."]);

    expect(GitRepositoryGrant::query()->count())->toBe(0);
});

it('lists the maintainers with their role and the grants by name, each saying whether they wait for a first sign-in', function () {
    [, $chitra] = grantWebPerson($this->organization, 'Chitra Nousername', title: 'Junior developer');
    $bina = grantWebGrant($this->api, $this->bina, GitRepositoryGrant::READ);
    $chitraGrant = grantWebGrant($this->api, $chitra);
    $asha = grantWebGrant($this->api, $this->asha);
    grantWebGrant($this->web, $this->teamMember);

    $data = $this->actingAs($this->managerUser)->getJson($this->base)->assertOk()->json('data');

    expect($data['maintainers'])->toEqual([
        ['id' => $this->manager->id, 'name' => 'Mira Manager', 'title' => 'PM', 'role' => 'manager', 'waiting' => false],
        ['id' => $this->programManager->id, 'name' => 'Pradeep Program', 'title' => 'Engineering head', 'role' => 'program manager', 'waiting' => true],
    ])
        ->and(collect($data['grants'])->map(fn (array $g): array => collect($g)->except('granted_at')->all())->all())->toEqual([
            ['id' => $asha->id, 'permission' => 'write', 'waiting' => false, 'effective' => true, 'member' => ['id' => $this->asha->id, 'name' => 'Asha Teammate', 'title' => 'Senior developer']],
            ['id' => $bina->id, 'permission' => 'read', 'waiting' => true, 'effective' => true, 'member' => ['id' => $this->bina->id, 'name' => 'Bina Colleague', 'title' => $this->bina->title]],
            ['id' => $chitraGrant->id, 'permission' => 'write', 'waiting' => true, 'effective' => true, 'member' => ['id' => $chitra->id, 'name' => 'Chitra Nousername', 'title' => 'Junior developer']],
        ])
        ->and($data['grants'][0]['granted_at'])->toBe($asha->created_at->toIso8601String());
});

it('lists a grant that gives nothing now as not effective: a deactivated person, or one moved to another organization and off the team', function () {
    [, $chitra] = grantWebPerson($this->organization, 'Chitra Colleague');
    $asha = grantWebGrant($this->api, $this->asha);
    $bina = grantWebGrant($this->api, $this->bina, GitRepositoryGrant::READ);
    $chitraGrant = grantWebGrant($this->api, $chitra);
    grantWebGrant($this->api, $this->teamMember);
    DB::table('members')->where('id', $this->asha->id)->update(['is_active' => false]);
    DB::table('members')->where('id', $this->bina->id)->update(['organization_id' => Organization::factory()->create()->id]);

    $grants = $this->actingAs($this->adminUser)->getJson($this->base)->assertOk()->json('data.grants');

    expect(collect($grants)->mapWithKeys(fn (array $g): array => [$g['id'] => $g['effective']])->all())->toEqual([
        $asha->id => false,
        $bina->id => false,
        $chitraGrant->id => true,
        GitRepositoryGrant::query()->where('member_id', $this->teamMember->id)->value('id') => true,
    ])
        ->and(GitRepositoryGrant::query()->effective()->where('git_repository_id', $this->api->id)->pluck('id')->sort()->values()->all())
        ->toEqual(collect($grants)->where('effective', true)->pluck('id')->sort()->values()->all());
});

it('lists a program manager who also manages the project once, as its manager', function () {
    $this->team->members()->detach($this->manager->id);
    $this->team->members()->attach($this->programManager->id, ['role' => 'leader']);

    $maintainers = $this->actingAs($this->adminUser)->getJson($this->base)->assertOk()->json('data.maintainers');

    expect($maintainers)->toEqual([
        ['id' => $this->programManager->id, 'name' => 'Pradeep Program', 'title' => 'Engineering head', 'role' => 'manager', 'waiting' => true],
    ]);
});

// Failures

it('refuses a permission other than read or write', function (string $permission) {
    $grant = grantWebGrant($this->api, $this->asha);
    $this->actingAs($this->adminUser);

    $this->post($this->base, ['member_id' => $this->bina->id, 'permission' => $permission])->assertSessionHasErrors('permission');
    $this->patch("{$this->base}/{$grant->id}", ['permission' => $permission])->assertSessionHasErrors('permission');

    expect(GitRepositoryGrant::query()->sole()->permission)->toBe('write');
})->with(['admin', 'owner', '']);

it('refuses someone the tracker doesn\'t have, as it refuses an outsider', function () {
    $gone = Member::factory()->create(['organization_id' => $this->organization->id]);
    $gone->delete();
    $this->actingAs($this->adminUser);

    foreach ([999999, $gone->id] as $id) {
        $this->post($this->base, ['member_id' => $id, 'permission' => 'read'])
            ->assertSessionHasErrors(['member_id' => "Choose someone from this organization or this project's team."]);
    }

    expect(GitRepositoryGrant::query()->count())->toBe(0);
});

it('survives the same grant made a moment earlier by someone else', function () {
    $lookups = 0;
    DB::listen(function (QueryExecuted $query) use (&$lookups): void {
        if (str_contains($query->sql, 'from "git_repository_grants" where ("git_repository_id" = ? and "member_id" = ?)') && ++$lookups === 1) {
            grantWebGrant($this->api, $this->asha, GitRepositoryGrant::WRITE, $this->adminUser);
        }
    });

    $this->actingAs($this->managerUser)
        ->post($this->base, ['member_id' => $this->asha->id, 'permission' => 'read'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Asha Teammate now has read access to sdoc/tp-api.']);

    expect($lookups)->toBe(2)
        ->and(GitRepositoryGrant::query()->sole()->only(['member_id', 'permission']))->toEqual(['member_id' => $this->asha->id, 'permission' => 'read']);
});

// Privilege escalation

it('refuses a project manager giving access to someone of the organization who is not on the team', function () {
    $this->actingAs($this->managerUser)
        ->post($this->base, ['member_id' => $this->bina->id, 'permission' => 'write'])
        ->assertSessionHasErrors(['member_id' => "You can't give Bina Colleague access to this repository."]);

    expect(GitRepositoryGrant::query()->count())->toBe(0);
    Queue::assertNotPushed(SyncRepositoryAccess::class);
});

it('refuses access to a maintainer or someone inactive, naming them', function () {
    $inactive = Member::factory()->inactive()->create(['organization_id' => $this->organization->id, 'name' => 'Era Inactive']);
    $this->actingAs($this->adminUser);

    foreach ([$this->manager, $this->programManager, $inactive] as $member) {
        $this->post($this->base, ['member_id' => $member->id, 'permission' => 'read'])
            ->assertSessionHasErrors(['member_id' => "You can't give {$member->name} access to this repository."]);
    }

    expect(GitRepositoryGrant::query()->count())->toBe(0);
});

it('never names someone outside the organization and the project\'s team', function (string $who) {
    $stranger = Member::factory()->create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Dipak Stranger']);
    $this->actingAs($who === 'manager' ? $this->managerUser : $this->adminUser);

    $response = $this->post($this->base, ['member_id' => $stranger->id, 'permission' => 'read'])
        ->assertSessionHasErrors(['member_id' => "Choose someone from this organization or this project's team."]);

    expect(implode(' ', session('errors')->getBag('default')->all()))->not->toContain('Dipak')
        ->and((string) $response->getContent())->not->toContain('Dipak')
        ->and(GitRepositoryGrant::query()->count())->toBe(0);
})->with(['manager', 'organizational admin']);

it('lets an organizational admin give access to an outsider on a cross-organization team', function () {
    $outsider = Member::factory()->create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Chandra Outsider']);
    $this->team->update(['is_cross_organization' => true]);
    $this->team->members()->attach($outsider->id, ['role' => 'member']);

    $this->actingAs($this->adminUser)
        ->post($this->base, ['member_id' => $outsider->id, 'permission' => 'write'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash.message', 'Gave Chandra Outsider write access to sdoc/tp-api.');

    expect(GitRepositoryGrant::query()->sole()->member_id)->toBe($outsider->id);
});

it('refuses a team member and another organization\'s organizational admin on every route', function (string $who) {
    $user = $who === 'team member'
        ? $this->teamMemberUser
        : grantWebPerson(Organization::factory()->create(), 'Olga Outside', admin: true)[0];
    $grant = grantWebGrant($this->api, $this->asha);
    $this->actingAs($user);

    $this->getJson($this->base)->assertForbidden();
    $this->getJson("{$this->base}/candidates?q=a")->assertForbidden();
    $this->post($this->base, ['member_id' => $this->bina->id, 'permission' => 'write'])->assertForbidden();
    $this->patch("{$this->base}/{$grant->id}", ['permission' => 'read'])->assertForbidden();
    $this->delete("{$this->base}/{$grant->id}")->assertForbidden();

    expect(GitRepositoryGrant::query()->sole()->permission)->toBe('write');
})->with(['team member', 'outside admin']);

it('finds a grant only under its own repository', function () {
    $elsewhere = grantWebGrant($this->web, $this->asha);
    $this->actingAs($this->adminUser);

    $this->patch("{$this->base}/{$elsewhere->id}", ['permission' => 'read'])->assertNotFound();
    $this->delete("{$this->base}/{$elsewhere->id}")->assertNotFound();

    expect($elsewhere->fresh()->permission)->toBe('write');
});

it('lets a project manager remove, but not raise, an organizational admin\'s grant to someone off the team', function () {
    $grant = grantWebGrant($this->api, $this->bina, GitRepositoryGrant::READ, $this->adminUser);
    $this->actingAs($this->managerUser);

    $this->patch("{$this->base}/{$grant->id}", ['permission' => 'write'])
        ->assertSessionHasErrors(['permission' => "You can't give Bina Colleague access to this repository."]);
    expect($grant->fresh()->permission)->toBe('read');

    $this->delete("{$this->base}/{$grant->id}")
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash.message', "Removed Bina Colleague's access to sdoc/tp-api.");
    expect(GitRepositoryGrant::query()->count())->toBe(0);
});

// Candidates

it('offers a project manager exactly the people the rule lets them give access to, without emails', function () {
    grantWebGrant($this->api, $this->asha);
    [, $newcomer] = grantWebPerson($this->organization, 'Nima Newcomer');
    $this->team->members()->attach($newcomer->id, ['role' => 'member']);

    $data = $this->actingAs($this->managerUser)->getJson("{$this->base}/candidates")->assertOk()->json('data');
    $ids = collect($data)->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(grantWebRuleSet($this->managerUser, $this->api))
        ->and($ids)->toBe(collect([$this->teamMember->id, $newcomer->id])->sort()->values()->all())
        ->and(grantWebKeys($data))->toBe(['id', 'name', 'title']);
});

it('offers an organizational admin exactly the people the rule lets them give access to, without emails', function () {
    grantWebGrant($this->api, $this->asha);
    Member::factory()->inactive()->create(['organization_id' => $this->organization->id]);
    Member::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $data = $this->actingAs($this->adminUser)->getJson("{$this->base}/candidates")->assertOk()->json('data');
    $ids = collect($data)->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(grantWebRuleSet($this->adminUser, $this->api))
        ->and($ids)->toContain($this->bina->id)
        ->and(grantWebKeys($data))->toBe(['id', 'name', 'title']);
});

it('filters candidates by the search term', function () {
    $data = $this->actingAs($this->adminUser)->getJson("{$this->base}/candidates?q=bina")->assertOk()->json('data');

    expect($data)->toBe([['id' => $this->bina->id, 'name' => 'Bina Colleague', 'title' => $this->bina->title]]);
});
