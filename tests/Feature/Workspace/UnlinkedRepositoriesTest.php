<?php

use App\Jobs\BackfillGitRepository;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectKeyAlias;
use App\Models\User;
use App\Queries\Maintenance\MaintenanceQuery;
use App\Queries\UnlinkedRepositoriesQuery;
use App\Support\ProjectKeys;
use App\Support\WorkspaceAccess;
use App\Support\WorkspaceOrganization;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

/*
 * Repositories on the forge that no project links: the notice on Code
 * activity, with "Link to a project" and "New project for it", and the
 * Maintenance check. SDOC's forge organization holds sdoc/app, sdoc/sdoc,
 * sdoc/ird-prize, sdoc/runner-smoke (newest activity first) and the archived
 * sdoc/old-site. Forge fixtures follow Forgejo 15's api.Repository.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com'], 'app.url' => 'https://tasks.example.test']);

    $this->sdoc = Organization::factory()->create(['name' => 'SDOC']);
    $this->mof = Organization::factory()->create(['name' => 'MoF']);
    $this->connection = unlinkedConnection($this->sdoc, 'sdoc');
    $this->mofConnection = unlinkedConnection($this->mof, 'mof');
    $this->tax = Project::factory()->create(['organization_id' => $this->sdoc->id, 'title' => 'Tax Portal', 'key' => 'TP']);

    $this->boss = User::factory()->create(['email' => 'boss@example.com']);
    $this->admin = unlinkedPerson($this->sdoc, officeAdmin: true);
    $this->manager = unlinkedPerson($this->sdoc, team: $this->tax, role: 'leader');
    $this->member = unlinkedPerson($this->sdoc, team: $this->tax);
    $this->programManager = unlinkedPerson($this->sdoc);
    $this->outsideAdmin = unlinkedPerson($this->mof, officeAdmin: true);
    $this->revenue = Program::factory()->create([
        'organization_id' => $this->sdoc->id,
        'name' => 'Revenue',
        'manager_id' => WorkspaceAccess::memberFor($this->programManager)?->id,
    ]);
});

function unlinkedConnection(Organization $organization, string $owner): GitConnection
{
    return GitConnection::query()->create([
        'organization_id' => $organization->id,
        'base_url' => 'https://git.example',
        'owner' => $owner,
        'token' => "{$owner}-bot-token-never-shown",
        'webhook_id' => 99,
        'webhook_secret' => 'hook-secret-never-shown',
    ]);
}

function unlinkedPerson(Organization $organization, bool $officeAdmin = false, ?Project $team = null, string $role = 'member'): User
{
    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id, 'is_office_admin' => $officeAdmin]);
    $team?->ensureTeam()->members()->attach($member->id, ['role' => $role]);

    return $user;
}

/** @return array<string, mixed> api.Repository */
function unlinkedForgeRepository(int $id, string $name, string $updatedAt = '2026-10-01T10:00:00+05:45', bool $archived = false, string $owner = 'sdoc'): array
{
    return [
        'id' => $id,
        'owner' => ['id' => 9, 'login' => $owner, 'full_name' => strtoupper($owner), 'username' => $owner],
        'name' => $name,
        'full_name' => "{$owner}/{$name}",
        'description' => "The {$name} code",
        'empty' => false,
        'private' => true,
        'fork' => false,
        'mirror' => false,
        'html_url' => "https://git.example/{$owner}/{$name}",
        'url' => "https://git.example/api/v1/repos/{$owner}/{$name}",
        'ssh_url' => "git@git.example:{$owner}/{$name}.git",
        'clone_url' => "https://git.example/{$owner}/{$name}.git",
        'default_branch' => 'main',
        'archived' => $archived,
        'created_at' => '2026-09-01T10:00:00+05:45',
        'updated_at' => $updatedAt,
    ];
}

/** @return list<array<string, mixed>> what sdoc holds on the forge */
function unlinkedForgeList(): array
{
    return [
        unlinkedForgeRepository(501, 'app', '2026-11-03T09:00:00+05:45'),
        unlinkedForgeRepository(502, 'ird-prize', '2026-11-02T10:00:00+05:45'),
        unlinkedForgeRepository(503, 'sdoc', '2026-11-03T08:00:00+05:45'),
        unlinkedForgeRepository(504, 'old-site', '2026-11-03T09:30:00+05:45', archived: true),
        unlinkedForgeRepository(505, 'runner-smoke', '2026-10-01T10:00:00+05:45'),
    ];
}

/**
 * A list endpoint: $rows on the first page and nothing after.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function unlinkedPage(array $rows): Closure
{
    return function (Request $request) use ($rows) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(($query['page'] ?? '1') === '1' ? $rows : []);
    };
}

/**
 * The forge: sdoc's repositories, mof's none, ird-prize readable for a link,
 * except what $stubs replace.
 *
 * @param  array<string, mixed>  $stubs
 */
function unlinkedFakeForge(array $stubs = []): void
{
    $prize = unlinkedForgeRepository(502, 'ird-prize', '2026-11-02T10:00:00+05:45');

    Http::fake(array_merge([
        'git.example/api/v1/orgs/sdoc/repos*' => unlinkedPage(unlinkedForgeList()),
        'git.example/api/v1/orgs/mof/repos*' => unlinkedPage([]),
        'git.example/api/v1/repositories/502' => Http::response($prize),
        'git.example/api/v1/repos/sdoc/ird-prize' => Http::response($prize),
        'git.example/api/v1/repos/sdoc/ird-prize/collaborators*' => unlinkedPage([]),
    ], $stubs));
}

/** How many times the forge organization's repositories were listed (each listing's first page). */
function unlinkedListings(string $owner = 'sdoc'): int
{
    return Http::recorded(function (Request $request) use ($owner): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), "https://git.example/api/v1/orgs/{$owner}/repos?") && ($query['page'] ?? null) === '1';
    })->count();
}

function unlinkedLink(Project $project, GitConnection $connection, int $forgeId, string $name): GitRepository
{
    return GitRepository::query()->create([
        'git_connection_id' => $connection->id,
        'project_id' => $project->id,
        'forge_id' => $forgeId,
        'owner' => $connection->owner,
        'name' => $name,
        'full_name' => "{$connection->owner}/{$name}",
        'html_url' => "https://git.example/{$connection->owner}/{$name}",
        'clone_url' => "https://git.example/{$connection->owner}/{$name}.git",
        'ssh_url' => "git@git.example:{$connection->owner}/{$name}.git",
        'default_branch' => 'main',
    ]);
}

/** @return array{forge_id: int, full_name: string, name: string, description: string, html_url: string, updated_at: string} */
function unlinkedRow(int $forgeId, string $name, string $updatedAt): array
{
    return [
        'forge_id' => $forgeId,
        'full_name' => "sdoc/{$name}",
        'name' => $name,
        'description' => "The {$name} code",
        'html_url' => "https://git.example/sdoc/{$name}",
        'updated_at' => $updatedAt,
    ];
}

// The list

it("lists the forge organization's repositories no project links, without archived ones, newest activity first", function () {
    unlinkedLink($this->tax, $this->connection, 501, 'app');
    // The same forge id linked under another connection is not this one's.
    unlinkedLink(Project::factory()->create(['organization_id' => $this->mof->id]), $this->mofConnection, 503, 'theirs');
    unlinkedFakeForge();

    expect(app(UnlinkedRepositoriesQuery::class)->for($this->connection))->toEqual([
        'unavailable' => false,
        'repositories' => [
            unlinkedRow(503, 'sdoc', '2026-11-03T08:00:00+05:45'),
            unlinkedRow(502, 'ird-prize', '2026-11-02T10:00:00+05:45'),
            unlinkedRow(505, 'runner-smoke', '2026-10-01T10:00:00+05:45'),
        ],
    ]);
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://git.example/api/v1/orgs/sdoc/repos?')
        && $r->hasHeader('Authorization', 'token sdoc-bot-token-never-shown'));
});

it('keeps the list for five minutes per connection', function () {
    unlinkedFakeForge();
    $query = app(UnlinkedRepositoriesQuery::class);

    $query->for($this->connection);
    $query->for($this->connection);
    expect(unlinkedListings())->toBe(1);

    $this->travel(UnlinkedRepositoriesQuery::TTL_SECONDS + 1)->seconds();
    $query->for($this->connection);
    expect(unlinkedListings())->toBe(2);
});

it('reads the forge again once a repository of the connection is linked or unlinked', function () {
    unlinkedFakeForge();
    $query = app(UnlinkedRepositoriesQuery::class);
    $ids = fn (): array => array_column($query->for($this->connection)['repositories'], 'forge_id');

    expect($ids())->toBe([501, 503, 502, 505]);

    // Another connection's link leaves this one's list where it is.
    unlinkedLink(Project::factory()->create(['organization_id' => $this->mof->id]), $this->mofConnection, 900, 'theirs');
    expect($ids())->toBe([501, 503, 502, 505])
        ->and(unlinkedListings())->toBe(1);

    $linked = unlinkedLink($this->tax, $this->connection, 502, 'ird-prize');
    expect($ids())->toBe([501, 503, 505])
        ->and(unlinkedListings())->toBe(2);

    $linked->delete();
    expect($ids())->toBe([501, 503, 502, 505])
        ->and(unlinkedListings())->toBe(3);
});

it('answers unavailable, never an error, when the forge cannot list them, and waits out the cache before asking again', function (string $failure) {
    Http::fake(['git.example/*' => $failure === 'unreachable'
        ? fn () => throw new ConnectionException('cURL error 7: Failed to connect')
        : Http::response(['message' => 'no'], (int) $failure)]);
    $query = app(UnlinkedRepositoriesQuery::class);

    expect($query->for($this->connection))->toBe(['unavailable' => true, 'repositories' => []])
        ->and($query->for($this->connection))->toBe(['unavailable' => true, 'repositories' => []]);
    Http::assertSentCount($failure === 'unreachable' ? 0 : 1);
})->with(['500', '401', 'unreachable']);

it('names a new project after the repository', function (string $name, string $title) {
    expect(UnlinkedRepositoriesQuery::titleFor($name))->toBe($title);
})->with([
    ['ird-prize', 'IRD Prize'],
    ['ird-lottery', 'IRD Lottery'],
    ['sdoc', 'SDOC'],
    ['runner-smoke', 'Runner Smoke'],
    ['payroll_system.web', 'Payroll System Web'],
    ['a-b', 'A B'],
]);

// Who sees them

it('shows them to whoever administers the organization: its organizational admin and a super-admin', function () {
    expect(WorkspaceAccess::canSeeUnlinkedRepositories($this->admin, $this->connection))->toBeTrue()
        ->and(WorkspaceAccess::canSeeUnlinkedRepositories($this->boss, $this->connection))->toBeTrue()
        ->and(WorkspaceAccess::canSeeUnlinkedRepositories($this->boss, $this->mofConnection))->toBeTrue()
        ->and(WorkspaceAccess::canSeeUnlinkedRepositories($this->outsideAdmin, $this->mofConnection))->toBeTrue();
});

it("hides them from a project manager, a program manager, a member, another organization's admin, a deactivated admin and no one", function (string $who) {
    $user = match ($who) {
        'manager' => $this->manager,
        'program manager' => $this->programManager,
        'member' => $this->member,
        'outside admin' => $this->outsideAdmin,
        'deactivated admin' => tap($this->admin, fn (User $user) => WorkspaceAccess::memberFor($user)?->update(['is_active' => false])),
        'no one' => null,
    };
    WorkspaceAccess::flush();

    expect(WorkspaceAccess::canSeeUnlinkedRepositories($user, $this->connection))->toBeFalse();
})->with(['manager', 'program manager', 'member', 'outside admin', 'deactivated admin', 'no one']);

it('hides them in an organization without git, even from a super-admin', function () {
    $gone = $this->connection;
    $gone->delete();

    expect(WorkspaceAccess::canSeeUnlinkedRepositories($this->boss, $gone))->toBeFalse()
        ->and(WorkspaceAccess::canSeeUnlinkedRepositories($this->admin, $gone))->toBeFalse()
        ->and(WorkspaceAccess::canSeeUnlinkedRepositories($this->boss, new GitConnection(['organization_id' => $this->sdoc->id])))->toBeFalse();
});

// Code activity

it("gives Code activity the organization's unlinked repositories, with what a new project would be called and the projects to link to", function () {
    unlinkedLink($this->tax, $this->connection, 501, 'app');
    $prize = Project::factory()->create(['organization_id' => $this->sdoc->id, 'title' => 'Old Prize', 'key' => 'IP']);
    $lottery = Project::factory()->create(['organization_id' => $this->sdoc->id, 'title' => 'Lottery', 'key' => 'L', 'program_id' => $this->revenue->id]);
    Project::factory()->create(['organization_id' => $this->sdoc->id, 'title' => 'Archived', 'archived_at' => now()]);
    unlinkedFakeForge();

    $props = $this->actingAs($this->admin)->get('/workspace/reports/code')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/CodeActivity'))
        ->viewData('page')['props'];

    expect($props['unlinked'])->toEqual([[
        'connection' => ['id' => $this->connection->id, 'organization' => ['id' => $this->sdoc->id, 'name' => 'SDOC']],
        'unavailable' => false,
        'repositories' => [
            [...unlinkedRow(503, 'sdoc', '2026-11-03T08:00:00+05:45'), 'suggested_title' => 'SDOC', 'suggested_key' => 'S'],
            // IP is Old Prize's: the key is the next one free.
            [...unlinkedRow(502, 'ird-prize', '2026-11-02T10:00:00+05:45'), 'suggested_title' => 'IRD Prize', 'suggested_key' => 'IPB'],
            [...unlinkedRow(505, 'runner-smoke', '2026-10-01T10:00:00+05:45'), 'suggested_title' => 'Runner Smoke', 'suggested_key' => 'RS'],
        ],
        'projects' => [
            ['id' => $lottery->id, 'slug' => $lottery->slug, 'title' => 'Lottery', 'program' => 'Revenue', 'organization_id' => $this->sdoc->id, 'mine' => false],
            ['id' => $prize->id, 'slug' => $prize->slug, 'title' => 'Old Prize', 'program' => null, 'organization_id' => $this->sdoc->id, 'mine' => false],
            ['id' => $this->tax->id, 'slug' => $this->tax->slug, 'title' => 'Tax Portal', 'program' => null, 'organization_id' => $this->sdoc->id, 'mine' => false],
        ],
        'programs' => [['id' => $this->revenue->id, 'name' => 'Revenue']],
    ]]);
    // The sidebar's Maintenance count and the page share one listing.
    expect(unlinkedListings())->toBe(1);
});

it('suggests each key as ProjectKeys::unique would, in the same number of queries however many repositories wait', function () {
    Project::factory()->create(['organization_id' => $this->sdoc->id, 'title' => 'Old Prize', 'key' => 'IP']);
    ProjectKeyAlias::query()->create(['project_id' => $this->tax->id, 'organization_id' => $this->sdoc->id, 'key' => 'RS']);
    $rows = [];
    Http::fake(['git.example/api/v1/orgs/sdoc/repos*' => function (Request $request) use (&$rows) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(($query['page'] ?? '1') === '1' ? $rows : []);
    }]);
    $read = function (array $repositories) use (&$rows): array {
        $rows = $repositories;
        Cache::flush();
        WorkspaceAccess::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $notices = app(UnlinkedRepositoriesQuery::class)->forViewer($this->admin);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$notices[0]['repositories'], $queries];
    };

    [, $few] = $read(array_slice(unlinkedForgeList(), 0, 2));
    [$many, $manyQueries] = $read([
        ...unlinkedForgeList(),
        ...array_map(fn (int $i): array => unlinkedForgeRepository(600 + $i, "ird-tool-{$i}"), range(1, 6)),
    ]);

    expect($manyQueries)->toBe($few)
        ->and(array_column($many, 'suggested_key', 'name'))->toEqual(collect($many)
        ->mapWithKeys(fn (array $r): array => [$r['name'] => ProjectKeys::unique($this->sdoc->id, ProjectKeys::suggest($r['suggested_title']))])
        ->all())
        // IP is a project's key and RS an old key: each is the next one free.
        ->and(array_column($many, 'suggested_key', 'name'))->toMatchArray(['ird-prize' => 'IPB', 'runner-smoke' => 'RSB', 'sdoc' => 'S']);
});

it('gives a super-admin every organization on "All organizations", and the one they switched to', function () {
    unlinkedFakeForge();

    $all = $this->actingAs($this->boss)->get('/workspace/reports/code')->assertOk()->viewData('page')['props']['unlinked'];

    expect(array_column(array_column($all, 'connection'), 'id'))->toBe([$this->mofConnection->id, $this->connection->id])
        ->and($all[0]['repositories'])->toBe([])
        ->and(count($all[1]['repositories']))->toBe(4);

    WorkspaceOrganization::choose($this->boss, $this->sdoc->id);

    $one = $this->actingAs($this->boss)->get('/workspace/reports/code')->assertOk()->viewData('page')['props']['unlinked'];

    expect(array_column(array_column($one, 'connection'), 'id'))->toBe([$this->connection->id]);
});

it('gives Code activity no unlinked repositories, and never asks the forge, for anyone who does not administer the organization', function (string $who) {
    unlinkedFakeForge();

    $this->actingAs($this->{$who})
        ->get('/workspace/reports/code')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('unlinked', []));

    Http::assertNothingSent();
})->with(['manager', 'programManager', 'member']);

it("gives another organization's admin only their own organization's entry, never SDOC's repositories", function () {
    unlinkedFakeForge();

    $unlinked = $this->actingAs($this->outsideAdmin)->get('/workspace/reports/code')->assertOk()->viewData('page')['props']['unlinked'];

    expect(array_column(array_column($unlinked, 'connection'), 'id'))->toBe([$this->mofConnection->id])
        ->and($unlinked[0]['repositories'])->toBe([])
        ->and(json_encode($unlinked))->not->toContain('ird-prize')
        ->and(json_encode($unlinked))->not->toContain('sdoc/')
        ->and(unlinkedListings('sdoc'))->toBe(0);
});

it('says the forge could not be read rather than failing the page', function () {
    Http::fake(['git.example/*' => Http::response([], 502)]);

    $this->actingAs($this->admin)
        ->get('/workspace/reports/code')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('unlinked.0.unavailable', true)
            ->where('unlinked.0.repositories', []));
});

it("says the forge could not be read when the connection's token was sealed with another app key", function () {
    // A copy of production's database on a machine with its own APP_KEY.
    DB::table('git_connections')->where('id', $this->connection->id)->update(['token' => 'sealed-elsewhere']);
    Http::fake();

    $this->actingAs($this->admin)
        ->get('/workspace/reports/code')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('unlinked.0.unavailable', true)
            ->where('unlinked.0.repositories', []));

    Http::assertNothingSent();
});

// New project for it

it('creates a project for the repository, managed by the caller, and links the repository to it', function () {
    Queue::fake();
    unlinkedFakeForge();

    $this->actingAs($this->admin)
        ->from('/workspace/reports/code')
        ->post("/workspace/git/connections/{$this->connection->id}/repositories/502/project", [
            'title' => 'IRD Prize',
            'key' => 'irp',
            'program_id' => $this->revenue->id,
        ])
        ->assertRedirect('/workspace/reports/code')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Created IRD Prize and linked sdoc/ird-prize. Its earlier commits are being read now.']);

    $project = Project::query()->where('title', 'IRD Prize')->sole();
    expect($project->only(['organization_id', 'key', 'program_id', 'is_public']))->toEqual([
        'organization_id' => $this->sdoc->id,
        'key' => 'IRP',
        'program_id' => $this->revenue->id,
        'is_public' => false,
    ])
        ->and($project->manager()?->id)->toBe(WorkspaceAccess::memberFor($this->admin)?->id)
        ->and(GitRepository::query()->sole()->only(['project_id', 'forge_id', 'full_name']))->toEqual([
            'project_id' => $project->id,
            'forge_id' => 502,
            'full_name' => 'sdoc/ird-prize',
        ]);
    Queue::assertPushed(BackfillGitRepository::class, fn (BackfillGitRepository $job) => $job->repository->project_id === $project->id);
    // The write reads the forge afresh, never the cached list.
    expect(unlinkedListings())->toBe(0);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://git.example/api/v1/repositories/502');
});

it("suggests the key from the title when none is given, and leaves a super-admin who isn't one of the organization's people off the team", function () {
    Queue::fake();
    unlinkedFakeForge();

    $this->actingAs($this->boss)
        ->post("/workspace/git/connections/{$this->connection->id}/repositories/502/project", ['title' => 'IRD Prize'])
        ->assertSessionHasNoErrors();

    $project = Project::query()->where('title', 'IRD Prize')->sole();
    expect($project->key)->toBe('IP')
        ->and($project->program_id)->toBeNull()
        ->and($project->manager())->toBeNull()
        ->and($project->repositories()->sole()->forge_id)->toBe(502);
});

it('creates nothing when the repository cannot be linked, and says why', function (string $case, string $message) {
    Queue::fake();
    unlinkedFakeForge($case === 'collaborators unreadable'
        ? ['git.example/api/v1/repos/sdoc/ird-prize/collaborators*' => Http::response([], 500)]
        : ['git.example/api/v1/repositories/502' => Http::response([], 503)]);
    $projects = Project::query()->count();

    $this->actingAs($this->admin)
        ->post("/workspace/git/connections/{$this->connection->id}/repositories/502/project", ['title' => 'IRD Prize'])
        ->assertSessionHasErrors(['__global' => $message]);

    expect(Project::query()->count())->toBe($projects)
        ->and(GitRepository::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    ['collaborators unreadable', "Couldn't read sdoc/ird-prize's collaborators on the forge, so it wasn't linked. Try again shortly."],
    ['forge down', "Couldn't reach the forge at https://git.example."],
]);

it('refuses a repository that is no longer waiting to be linked', function (string $case) {
    Queue::fake();
    $stubs = match ($case) {
        'linked meanwhile' => [],
        'in another forge organization' => ['git.example/api/v1/repositories/502' => Http::response(unlinkedForgeRepository(502, 'ird-prize', owner: 'mof'))],
        'gone from the forge' => ['git.example/api/v1/repositories/502' => Http::response(['message' => 'not found'], 404)],
        'archived' => ['git.example/api/v1/repositories/502' => Http::response(unlinkedForgeRepository(502, 'ird-prize', archived: true))],
    };
    if ($case === 'linked meanwhile') {
        unlinkedLink($this->tax, $this->connection, 502, 'ird-prize');
    }
    unlinkedFakeForge($stubs);
    $projects = Project::query()->count();
    $query = app(UnlinkedRepositoriesQuery::class);
    $query->for($this->connection);

    $this->actingAs($this->admin)
        ->post("/workspace/git/connections/{$this->connection->id}/repositories/502/project", ['title' => 'IRD Prize'])
        ->assertSessionHasErrors(['__global' => 'That repository is no longer waiting to be linked.']);

    // The cached list that offered it is forgotten: the forge is read again.
    $query->for($this->connection);
    expect(Project::query()->count())->toBe($projects)
        ->and(unlinkedListings())->toBe(2);
})->with(['linked meanwhile', 'in another forge organization', 'gone from the forge', 'archived']);

it('creates nothing when the name now belongs to another repository (renamed meanwhile)', function () {
    Queue::fake();
    unlinkedFakeForge(['git.example/api/v1/repos/sdoc/ird-prize' => Http::response(unlinkedForgeRepository(777, 'ird-prize'))]);
    $projects = Project::query()->count();

    $this->actingAs($this->admin)
        ->post("/workspace/git/connections/{$this->connection->id}/repositories/502/project", ['title' => 'IRD Prize'])
        ->assertSessionHasErrors(['__global' => 'That repository is no longer waiting to be linked.']);

    expect(Project::query()->count())->toBe($projects)
        ->and(GitRepository::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it("refuses a project manager, a program manager, a member and another organization's admin", function (string $who) {
    unlinkedFakeForge();
    $projects = Project::query()->count();

    $this->actingAs($this->{$who})
        ->post("/workspace/git/connections/{$this->connection->id}/repositories/502/project", ['title' => 'IRD Prize', 'program_id' => $this->revenue->id])
        ->assertForbidden();

    expect(Project::query()->count())->toBe($projects);
    Http::assertNothingSent();
})->with(['manager', 'programManager', 'member', 'outsideAdmin']);

it('checks the title, the key and the program', function (array $input, string $field, string $message) {
    unlinkedFakeForge();
    $other = Program::factory()->create(['organization_id' => $this->mof->id, 'name' => 'Budget']);
    $input = array_map(fn (mixed $value): mixed => $value === 'other program' ? $other->id : $value, $input);

    $this->actingAs($this->admin)
        ->post("/workspace/git/connections/{$this->connection->id}/repositories/502/project", $input)
        ->assertSessionHasErrors([$field => $message]);

    expect(Project::query()->where('title', 'IRD Prize')->exists())->toBeFalse();
    Http::assertNothingSent();
})->with([
    'no title' => [['title' => ''], 'title', 'The title field is required.'],
    'a taken key' => [['title' => 'IRD Prize', 'key' => 'tp'], 'key', 'That key is already used in this organization.'],
    'a key that is not letters' => [['title' => 'IRD Prize', 'key' => 'I2'], 'key', 'The key field format is invalid.'],
    "another organization's program" => [['title' => 'IRD Prize', 'program_id' => 'other program'], 'program_id', 'That program belongs to another organization.'],
]);

// Maintenance

it("counts the forge's unlinked repositories on Maintenance, from the cached list", function () {
    unlinkedLink($this->tax, $this->connection, 501, 'app');
    $rows = [unlinkedForgeRepository(501, 'app')];
    Http::fake(['git.example/api/v1/orgs/sdoc/repos*' => function (Request $request) use (&$rows) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(($query['page'] ?? '1') === '1' ? $rows : []);
    }]);
    $maintenance = app(MaintenanceQuery::class);

    expect($maintenance->get($this->sdoc->id)['repositories_unlinked'])->toBe(0);
    $before = $maintenance->count($this->sdoc->id);

    $rows = unlinkedForgeList();
    Cache::flush();

    expect($maintenance->get($this->sdoc->id)['repositories_unlinked'])->toBe(3)
        ->and($maintenance->count($this->sdoc->id))->toBe($before + 3)
        ->and(unlinkedListings())->toBe(2);

    // The sidebar's count, worked out again, asks the forge no more than the list's cache allows.
    Cache::forget("maintenance-count:{$this->sdoc->id}");
    expect($maintenance->count($this->sdoc->id))->toBe($before + 3)
        ->and(unlinkedListings())->toBe(2);
});

it("never asks the forge for the sidebar's count: it reads only the cached list, none counting as 0", function () {
    unlinkedFakeForge();
    $maintenance = app(MaintenanceQuery::class);

    // Nothing cached: the check counts nothing, and no page's sidebar asks the forge.
    $before = $maintenance->count($this->sdoc->id);
    Cache::forget("maintenance-count:{$this->sdoc->id}");
    $this->actingAs($this->admin)->get('/workspace/my')->assertOk();
    Cache::forget("maintenance-count:{$this->sdoc->id}");
    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/user')->assertOk()->assertJsonPath('office.maintenance_count', $before);
    Http::assertNothingSent();

    // Code activity reads the forge and fills the list; the count, worked
    // out again, takes it from there.
    $this->actingAs($this->admin)->get('/workspace/reports/code')->assertOk();
    Cache::forget("maintenance-count:{$this->sdoc->id}");

    expect($maintenance->count($this->sdoc->id))->toBe($before + 4)
        ->and(unlinkedListings())->toBe(1);
});

it('leaves the check out without git, and when the forge cannot list the repositories', function () {
    Http::fake(['git.example/*' => Http::response([], 500)]);
    $maintenance = app(MaintenanceQuery::class);

    expect($maintenance->get($this->sdoc->id)['repositories_unlinked'])->toBeNull();

    $this->connection->delete();
    Cache::flush();

    expect($maintenance->get($this->sdoc->id)['repositories_unlinked'])->toBeNull();
});

it('shows the check on the Maintenance page and its API', function () {
    unlinkedFakeForge();

    $this->actingAs($this->admin)
        ->get('/workspace/maintenance')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('maintenance.repositories_unlinked', 4));

    $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/v1/workspace/organizations/{$this->sdoc->id}/maintenance")
        ->assertOk()
        ->assertJsonPath('data.repositories_unlinked', 4);
});
