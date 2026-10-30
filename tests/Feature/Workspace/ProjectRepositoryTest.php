<?php

use App\Jobs\BackfillGitRepository;
use App\Models\GitBranch;
use App\Models\GitCommit;
use App\Models\GitConnection;
use App\Models\GitPullRequest;
use App\Models\GitRepository;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Task;
use App\Models\TaskGitLink;
use App\Models\User;
use App\Services\Git\ForgeShapes;
use App\Services\Git\GitIngest;
use App\Services\Git\RepositorySync;
use Carbon\CarbonInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * Forge fixtures follow Forgejo 15's API structs: api.Repository
 * (modules/structs/repo.go), api.Branch (repo_branch.go, whose commit is an
 * api.PayloadCommit), api.Commit (repo_commit.go, as services/convert/
 * git_commit.go ToCommit fills it) and api.PullRequest (pull.go).
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com'], 'app.url' => 'https://tasks.example.test']);

    $this->organization = Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
    $this->connection = GitConnection::query()->create([
        'organization_id' => $this->organization->id,
        'base_url' => 'https://git.example',
        'owner' => 'sdoc',
        'token' => 'bot-token-never-shown',
        'webhook_id' => 99,
        'webhook_secret' => 'hook-secret-never-shown',
    ]);
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Tax Portal', 'key' => 'TP']);
    $this->task = Task::factory()->for($this->project)->create();
    $this->base = "/workspace/projects/{$this->project->slug}/repositories";
});

/**
 * Someone in a role on the project: its manager, a member of its team, its
 * organization's office admin, a super-admin, or another organization's
 * office admin.
 */
function repoActor(string $role, Project $project): User
{
    if ($role === 'super-admin') {
        return User::factory()->create(['email' => 'boss@example.com']);
    }

    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create([
        'organization_id' => $role === 'outside admin' ? Organization::factory()->create()->id : $project->organization_id,
        'is_office_admin' => in_array($role, ['office admin', 'outside admin'], true),
    ]);

    if (in_array($role, ['manager', 'member'], true)) {
        $project->ensureTeam()->members()->attach($member->id, ['role' => $role === 'manager' ? 'leader' : 'member']);
    }

    return $user;
}

function repoLinked(Project $project, GitConnection $connection, int $forgeId = 501, string $name = 'app'): GitRepository
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

/** @return array<string, mixed> api.Repository: the fields the tracker reads, and some around them */
function repoForge(int $id = 501, string $owner = 'sdoc', string $name = 'app'): array
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
        'archived' => false,
        'has_issues' => true,
        'has_pull_requests' => true,
        'permissions' => ['admin' => true, 'push' => true, 'pull' => true],
    ];
}

/** @return array<string, mixed> api.Branch, its head an api.PayloadCommit */
function repoForgeBranch(string $name, string $sha): array
{
    $author = ['name' => 'Bikash Dev', 'email' => 'bikash@example.com', 'username' => 'bikash'];

    return [
        'name' => $name,
        'commit' => [
            'id' => $sha,
            'message' => "Work on {$name}\n",
            'url' => "https://git.example/sdoc/app/commit/{$sha}",
            'author' => $author,
            'committer' => $author,
            'verification' => null,
            'timestamp' => '2026-10-28T10:00:00+05:45',
            'added' => null,
            'removed' => null,
            'modified' => null,
        ],
        'protected' => false,
        'required_approvals' => 0,
        'enable_status_check' => false,
        'status_check_contexts' => [],
        'user_can_push' => true,
        'user_can_merge' => true,
        'effective_branch_protection_name' => '',
    ];
}

/** @return array<string, mixed> api.Commit as the list endpoint returns it with stat, files and verification off */
function repoForgeCommit(string $sha, string $message, CarbonInterface $at, ?string $login = 'bikash'): array
{
    $date = $at->toRfc3339String();
    $user = $login === null ? null : ['id' => 12, 'login' => $login, 'full_name' => 'Bikash Dev', 'username' => $login];

    return [
        'url' => "https://git.example/api/v1/repos/sdoc/app/git/commits/{$sha}",
        'sha' => $sha,
        'created' => $date,
        'html_url' => "https://git.example/sdoc/app/commit/{$sha}",
        'commit' => [
            'url' => "https://git.example/api/v1/repos/sdoc/app/git/commits/{$sha}",
            'author' => ['name' => 'Bikash Dev', 'email' => 'bikash@example.com', 'date' => $date],
            'committer' => ['name' => 'Bikash Dev', 'email' => 'bikash@example.com', 'date' => $date],
            'message' => $message."\n",
            'tree' => ['url' => "https://git.example/api/v1/repos/sdoc/app/git/trees/{$sha}", 'sha' => $sha, 'created' => $date],
            'verification' => null,
        ],
        'author' => $user,
        'committer' => $user,
        'parents' => [],
        'files' => null,
        'stats' => null,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed> api.PullRequest (the struct a webhook carries too)
 */
function repoForgePullRequest(int $number, string $title, CarbonInterface $updatedAt, string $branch = 'work', array $overrides = []): array
{
    return array_merge([
        'id' => 70 + $number,
        'url' => "https://git.example/sdoc/app/pulls/{$number}",
        'number' => $number,
        'user' => ['id' => 12, 'login' => 'bikash', 'username' => 'bikash'],
        'title' => $title,
        'body' => '',
        'state' => 'open',
        'draft' => false,
        'html_url' => "https://git.example/sdoc/app/pulls/{$number}",
        'merged' => false,
        'merged_at' => null,
        'base' => ['label' => 'main', 'ref' => 'main', 'sha' => 'b1b2c3d4e5f60718293a4b5c6d7e8f9001122334', 'repo_id' => 501],
        'head' => ['label' => $branch, 'ref' => $branch, 'sha' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9001122334', 'repo_id' => 501],
        'created_at' => $updatedAt->subDay()->toRfc3339String(),
        'updated_at' => $updatedAt->toRfc3339String(),
        'closed_at' => null,
    ], $overrides);
}

/**
 * A list endpoint: $rows on the first page and nothing after, as Forgejo
 * pages a short list (no Link header).
 *
 * @param  list<array<string, mixed>>  $rows
 */
function repoPage(array $rows): Closure
{
    return function (Request $request) use ($rows) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(($query['page'] ?? '1') === '1' ? $rows : []);
    };
}

/**
 * The forge with sdoc/app and nothing in it, except what $stubs replace.
 *
 * @param  array<string, mixed>  $stubs
 */
function repoFakeForge(array $stubs = []): void
{
    Http::fake(array_merge([
        'git.example/api/v1/repos/sdoc/app' => Http::response(repoForge()),
        'git.example/api/v1/repos/sdoc/app/pulls*' => repoPage([]),
        'git.example/api/v1/repos/sdoc/app/branches*' => repoPage([]),
        'git.example/api/v1/repos/sdoc/app/commits*' => repoPage([]),
        'git.example/api/v1/repos/sdoc/app/collaborators*' => repoPage([]),
    ], $stubs));
}

/** @return array<string, string> the query of a sent request */
function repoQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

// Who may

it('lets the manager, the office admin and a super-admin link a repository', function (string $role) {
    Queue::fake();
    repoFakeForge();

    $this->actingAs(repoActor($role, $this->project))
        ->post($this->base, ['full_name' => 'sdoc/app'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash.message', 'Linked sdoc/app.');

    expect(GitRepository::query()->sole()->project_id)->toBe($this->project->id);
})->with(['manager', 'office admin', 'super-admin']);

it('lets the manager, the office admin and a super-admin search candidates and unlink', function (string $role) {
    Http::fake(['git.example/api/v1/orgs/sdoc/repos*' => repoPage([])]);
    // On the team before the link: joining a team with repositories syncs their access.
    $this->actingAs(repoActor($role, $this->project));
    $repo = repoLinked($this->project, $this->connection);

    $this->getJson("{$this->base}/candidates")->assertOk()->assertExactJson(['data' => []]);
    $this->delete("{$this->base}/{$repo->id}")->assertSessionHasNoErrors();

    expect(GitRepository::query()->count())->toBe(0);
})->with(['manager', 'office admin', 'super-admin']);

it('refuses a team member and another organization\'s office admin on every route', function (string $role) {
    Http::fake();
    // On the team before the link: joining a team with repositories syncs their access.
    $this->actingAs(repoActor($role, $this->project));
    $repo = repoLinked($this->project, $this->connection);

    $this->getJson("{$this->base}/candidates")->assertForbidden();
    $this->post($this->base, ['full_name' => 'sdoc/app'])->assertForbidden();
    $this->delete("{$this->base}/{$repo->id}")->assertForbidden();

    expect(GitRepository::query()->count())->toBe(1);
    Http::assertNothingSent();
})->with(['member', 'outside admin']);

it('answers 403 on every route, even to a super-admin, when the organization has no git', function () {
    Http::fake();
    $elsewhere = GitConnection::query()->create([
        'organization_id' => Organization::factory()->create()->id,
        'base_url' => 'https://git.example',
        'owner' => 'moha',
        'token' => 'another-token',
    ]);
    // A repository can't outlive its connection: this stray row only proves
    // that unlinking asks about the project's organization.
    $stray = repoLinked($this->project, $elsewhere);
    $this->connection->delete();
    $this->actingAs(repoActor('super-admin', $this->project));

    $this->getJson("{$this->base}/candidates")->assertForbidden();
    $this->post($this->base, ['full_name' => 'sdoc/app'])->assertForbidden();
    $this->delete("{$this->base}/{$stray->id}")->assertForbidden();

    expect($stray->fresh())->not->toBeNull();
    Http::assertNothingSent();
});

// Candidates

it('lists the forge organization\'s repositories that no project links, filtered by name in any case', function () {
    $this->actingAs(repoActor('manager', $this->project));
    $sms = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'SMS Gateway', 'key' => 'SG']);
    repoLinked($this->project, $this->connection, 501, 'app');
    repoLinked($sms, $this->connection, 503, 'docs');
    // Forge id 502 is linked under another forge's connection: not this one's.
    $otherForge = GitConnection::query()->create(['organization_id' => Organization::factory()->create()->id, 'base_url' => 'https://git.other', 'owner' => 'moha', 'token' => 't']);
    repoLinked(Project::factory()->create(['organization_id' => $otherForge->organization_id]), $otherForge, 502, 'api');
    Http::fake(['git.example/api/v1/orgs/sdoc/repos*' => repoPage([
        repoForge(501, 'sdoc', 'app'),
        repoForge(504, 'sdoc', 'Api-Gateway'),
        repoForge(502, 'sdoc', 'api'),
        repoForge(503, 'sdoc', 'docs'),
    ])]);

    expect($this->getJson("{$this->base}/candidates")->assertOk()->json('data'))->toEqual([
        ['full_name' => 'sdoc/api', 'name' => 'api', 'description' => 'The api code', 'html_url' => 'https://git.example/sdoc/api'],
        ['full_name' => 'sdoc/Api-Gateway', 'name' => 'Api-Gateway', 'description' => 'The Api-Gateway code', 'html_url' => 'https://git.example/sdoc/Api-Gateway'],
    ]);
    expect($this->getJson("{$this->base}/candidates?q=GATE")->json('data.*.full_name'))->toBe(['sdoc/Api-Gateway']);
    expect($this->getJson("{$this->base}/candidates?q=docs")->json('data'))->toBe([]);
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://git.example/api/v1/orgs/sdoc/repos?')
        && $r->hasHeader('Authorization', 'token bot-token-never-shown'));
});

it('gives at most 50 candidates', function () {
    Http::fake(['git.example/api/v1/orgs/sdoc/repos*' => repoPage(array_map(fn (int $i) => repoForge(600 + $i, 'sdoc', sprintf('repo-%02d', $i)), range(1, 60)))]);
    $this->actingAs(repoActor('office admin', $this->project));

    $names = $this->getJson("{$this->base}/candidates")->assertOk()->json('data.*.name');

    expect($names)->toHaveCount(50)
        ->and($names[0])->toBe('repo-01')
        ->and($names[49])->toBe('repo-50');
});

it('says so when the forge cannot list its repositories', function () {
    Http::fake(['git.example/*' => Http::response([], 500)]);
    $this->actingAs(repoActor('manager', $this->project));

    $this->getJson("{$this->base}/candidates")
        ->assertStatus(502)
        ->assertJsonPath('message', 'The forge answered 500.');
});

// Link

it('links the repository as the forge describes it, points the forge back here and queues the backfill', function () {
    Queue::fake();
    repoFakeForge();

    $this->actingAs(repoActor('office admin', $this->project))
        ->post($this->base, ['full_name' => 'sdoc/app'])
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Linked sdoc/app.']);

    $repo = GitRepository::query()->sole();
    expect($repo->only(['git_connection_id', 'project_id', 'forge_id', 'owner', 'name', 'full_name', 'html_url', 'clone_url', 'ssh_url', 'default_branch']))->toEqual([
        'git_connection_id' => $this->connection->id,
        'project_id' => $this->project->id,
        'forge_id' => 501,
        'owner' => 'sdoc',
        'name' => 'app',
        'full_name' => 'sdoc/app',
        'html_url' => 'https://git.example/sdoc/app',
        'clone_url' => 'https://git.example/sdoc/app.git',
        'ssh_url' => 'git@git.example:sdoc/app.git',
        'default_branch' => 'main',
    ]);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
        && $r->url() === 'https://git.example/api/v1/repos/sdoc/app'
        && $r->data() == [
            'has_issues' => true,
            'external_tracker' => [
                'external_tracker_url' => 'https://tasks.example.test',
                'external_tracker_format' => 'https://tasks.example.test/workspace/go/{user}/{index}',
                'external_tracker_style' => 'alphanumeric',
            ],
        ]);
    Queue::assertPushed(BackfillGitRepository::class, fn (BackfillGitRepository $job) => $job->repository->is($repo));
    expect(ProjectActivity::query()->where('project_id', $this->project->id)->pluck('description')->all())->toContain('Linked repository sdoc/app');
});

it('refuses a repository of another forge organization', function () {
    Queue::fake();
    Http::fake(['git.example/api/v1/repos/moha/app' => Http::response(repoForge(601, 'moha', 'app'))]);

    $this->actingAs(repoActor('office admin', $this->project))
        ->post($this->base, ['full_name' => 'moha/app'])
        ->assertSessionHasErrors(['__global' => "That repository isn't in the sdoc organization on the forge."]);

    expect(GitRepository::query()->count())->toBe(0);
    Http::assertSentCount(1);
    Queue::assertNothingPushed();
});

it('refuses a repository the forge doesn\'t have', function () {
    Queue::fake();
    Http::fake(['git.example/api/v1/repos/sdoc/nope' => Http::response(['message' => 'The target couldn\'t be found.'], 404)]);

    $this->actingAs(repoActor('manager', $this->project))
        ->post($this->base, ['full_name' => 'sdoc/nope'])
        ->assertSessionHasErrors(['__global' => "That repository isn't in the sdoc organization on the forge."]);

    expect(GitRepository::query()->count())->toBe(0);
});

it('refuses a repository already linked to a project, naming the project', function (string $where) {
    Queue::fake();
    repoFakeForge();
    $project = $where === 'this project'
        ? $this->project
        : Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'SMS Gateway', 'key' => 'SG']);
    repoLinked($project, $this->connection);

    $this->actingAs(repoActor('office admin', $this->project))
        ->post($this->base, ['full_name' => 'sdoc/app'])
        ->assertSessionHasErrors(['__global' => "That repository is already linked to {$project->title}."]);

    expect(GitRepository::query()->count())->toBe(1);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    Queue::assertNothingPushed();
})->with(['this project', 'another project']);

it('refuses the link when someone else links the repository a moment earlier', function () {
    Queue::fake();
    repoFakeForge();
    $sms = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'SMS Gateway', 'key' => 'SG']);
    // The rival lands right after the service's "already linked?" check and
    // before its own insert (outside the insert's savepoint, as a second
    // connection's commit would).
    $rival = fn () => repoLinked($sms, $this->connection);
    DB::listen(function (QueryExecuted $query) use (&$rival): void {
        if ($rival !== null && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'git_repositories')) {
            [$link, $rival] = [$rival, null];
            $link();
        }
    });

    $this->actingAs(repoActor('office admin', $this->project))
        ->post($this->base, ['full_name' => 'sdoc/app'])
        ->assertSessionHasErrors(['__global' => 'That repository is already linked to SMS Gateway.']);

    expect(GitRepository::query()->pluck('project_id')->all())->toBe([$sms->id]);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    Queue::assertNothingPushed();
});

it('doesn\'t name a project the person can\'t see', function () {
    Queue::fake();
    repoFakeForge();
    repoLinked(Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Secret Plan', 'key' => 'SP']), $this->connection);

    $this->actingAs(repoActor('manager', $this->project))
        ->post($this->base, ['full_name' => 'sdoc/app'])
        ->assertSessionHasErrors(['__global' => 'That repository is already linked to another project.']);
});

it('says so when the forge cannot be reached, and saves nothing', function () {
    Queue::fake();
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

    $this->actingAs(repoActor('manager', $this->project))
        ->post($this->base, ['full_name' => 'sdoc/app'])
        ->assertSessionHasErrors(['__global' => "Couldn't reach the forge at https://git.example."]);

    expect(GitRepository::query()->count())->toBe(0);
});

it('wants a forge repository name', function (mixed $fullName) {
    Http::fake();

    $this->actingAs(repoActor('manager', $this->project))
        ->post($this->base, ['full_name' => $fullName])
        ->assertSessionHasErrors('full_name');

    Http::assertNothingSent();
})->with([
    'missing' => [null],
    'no owner' => ['app'],
    'a path' => ['sdoc/app/extra'],
    'spaces' => ['sdoc/my app'],
    'dot-dot owner' => ['../app'],
    'dot-dot name' => ['sdoc/..'],
    'dot name' => ['./app'],
    'dot-dot both' => ['../..'],
]);

it('takes names with dots in them to the forge', function (string $fullName) {
    Queue::fake();
    Http::fake(['git.example/*' => Http::response([], 404)]);

    $this->actingAs(repoActor('manager', $this->project))
        ->post($this->base, ['full_name' => $fullName])
        ->assertSessionDoesntHaveErrors('full_name')
        ->assertSessionHasErrors(['__global' => "That repository isn't in the sdoc organization on the forge."]);
})->with(['sdoc/.profile', 'sdoc/app.v2', 'sdoc/...']);

it('keeps the link when the forge refuses the link back, and logs why without the token', function () {
    Queue::fake();
    Log::spy();
    Http::fake([
        'git.example/api/v1/repos/sdoc/app' => fn (Request $r) => $r->method() === 'PATCH'
            ? Http::response(['message' => 'token does not have at least one of required scope(s): [write:repository]'], 403)
            : Http::response(repoForge()),
        'git.example/api/v1/repos/sdoc/app/collaborators*' => repoPage([]),
    ]);

    $this->actingAs(repoActor('manager', $this->project))
        ->post($this->base, ['full_name' => 'sdoc/app'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash.message', "Linked sdoc/app. The forge link back couldn't be set; the nightly reconcile will retry.");

    expect(GitRepository::query()->count())->toBe(1);
    Queue::assertPushed(BackfillGitRepository::class);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === "Couldn't set the forge link back."
        && $context === ['repository' => 'sdoc/app', 'status' => 403])->once();
});

it('logs an unreachable forge\'s cause for the link back with the token masked', function () {
    Queue::fake();
    Log::spy();
    Http::fake([
        'git.example/api/v1/repos/sdoc/app' => fn (Request $r) => $r->method() === 'PATCH'
            ? throw new ConnectionException('cURL error 28: timed out after bot-token-never-shown')
            : Http::response(repoForge()),
        'git.example/api/v1/repos/sdoc/app/collaborators*' => repoPage([]),
    ]);

    $this->actingAs(repoActor('manager', $this->project))->post($this->base, ['full_name' => 'sdoc/app']);

    expect(GitRepository::query()->count())->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['status'] === 0
        && str_contains($context['cause'], 'cURL error 28')
        && ! str_contains(json_encode($context), 'bot-token-never-shown'))->once();
});

// Unlink

it('unlinks a repository with its code and links, and leaves the forge alone', function () {
    Http::fake();
    $repo = repoLinked($this->project, $this->connection);
    $ingest = app(GitIngest::class);
    $ingest->branch($repo, 'tp-1-login', 'a1b2c3d4e5f60718293a4b5c6d7e8f9001122334');
    $ingest->commit($repo, ['sha' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9001122334', 'message' => 'TP-1 fix', 'author_name' => 'Bikash', 'author_username' => 'bikash', 'url' => 'https://git.example/sdoc/app/commit/a1b2', 'committed_at' => '2026-10-28T10:00:00+05:45']);
    $ingest->pullRequest($repo, ['number' => 3, 'title' => 'TP-1 login', 'body' => null, 'state' => 'open', 'url' => 'https://git.example/sdoc/app/pulls/3', 'head_branch' => 'tp-1-login', 'author_username' => 'bikash', 'opened_at' => '2026-10-28T10:00:00+05:45', 'merged_at' => null, 'closed_at' => null, 'updated_at' => null]);
    expect(TaskGitLink::query()->count())->toBe(3);

    $this->actingAs(repoActor('manager', $this->project))
        ->delete("{$this->base}/{$repo->id}")
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => "Unlinked sdoc/app. The repository is untouched on the forge. People who had access keep it on the forge until it's changed there or the repository is linked again."]);

    expect(GitRepository::query()->count())->toBe(0)
        ->and(GitBranch::query()->count())->toBe(0)
        ->and(GitCommit::query()->count())->toBe(0)
        ->and(GitPullRequest::query()->count())->toBe(0)
        ->and(TaskGitLink::query()->count())->toBe(0);
    // Only the issue tracker is handed back; nothing else on the forge is touched.
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === 'https://git.example/api/v1/repos/sdoc/app' && $r->data() === ['has_issues' => true]);
    expect(ProjectActivity::query()->where('project_id', $this->project->id)->pluck('description')->all())->toContain('Unlinked repository sdoc/app');
});

it('gives the forge its own issue tracker back on unlink, and unlinks even when the forge refuses or is down', function (string $failure, int $status) {
    Log::spy();
    Http::fake($failure === 'down'
        ? fn () => throw new ConnectionException('cURL error 7 bot-token-never-shown')
        : ['git.example/*' => Http::response(['message' => 'boom'], 500)]);
    $repo = repoLinked($this->project, $this->connection);

    $this->actingAs(repoActor('manager', $this->project))
        ->delete("{$this->base}/{$repo->id}")
        ->assertSessionHas('workspace_flash.success', true);

    expect(GitRepository::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === "Couldn't point a repository back at the forge's own issue tracker."
        && $context['repository'] === 'sdoc/app'
        && $context['status'] === $status
        && ! str_contains(json_encode($context), 'bot-token-never-shown'))->once();
})->with([
    'refused' => ['refused', 500],
    'down' => ['down', 0],
]);

it('finds a repository only under its own project', function () {
    Http::fake();
    $sms = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'SMS Gateway', 'key' => 'SG']);
    $repo = repoLinked($sms, $this->connection);

    $this->actingAs(repoActor('office admin', $this->project))
        ->delete("{$this->base}/{$repo->id}")
        ->assertNotFound();

    expect($repo->fresh())->not->toBeNull();
});

// Backfill

it('backfills recent pull requests, every branch and the default branch\'s last 90 days, and links them', function () {
    $repo = repoLinked($this->project, $this->connection);
    $recent = 'c1c2c3d4e5f60718293a4b5c6d7e8f9001122334';
    $old = 'd1d2c3d4e5f60718293a4b5c6d7e8f9001122334';
    $at = now()->subDays(10)->startOfSecond();
    Http::fake([
        'git.example/api/v1/repos/sdoc/app/pulls*' => repoPage([
            repoForgePullRequest(3, 'TP-1 login', now()->subDays(2), 'tp-1-login'),
            repoForgePullRequest(2, 'TP-1 old idea', now()->subDays(120), 'old-idea', ['state' => 'closed', 'closed_at' => now()->subDays(120)->toRfc3339String()]),
        ]),
        'git.example/api/v1/repos/sdoc/app/branches*' => repoPage([
            repoForgeBranch('main', $recent),
            repoForgeBranch('tp-1-login', 'e1e2c3d4e5f60718293a4b5c6d7e8f9001122334'),
        ]),
        // Forgejo 15 ignores `since` on this endpoint, so an old commit comes back too.
        'git.example/api/v1/repos/sdoc/app/commits*' => repoPage([
            repoForgeCommit($recent, 'TP-1 fix the form', $at),
            repoForgeCommit($old, 'TP-1 ancient history', now()->subDays(100)),
        ]),
    ]);

    BackfillGitRepository::dispatchSync($repo);

    expect($repo->pullRequests()->pluck('number')->all())->toBe([3])
        ->and($repo->branches()->orderBy('name')->pluck('head_sha', 'name')->all())->toBe(['main' => $recent, 'tp-1-login' => 'e1e2c3d4e5f60718293a4b5c6d7e8f9001122334'])
        ->and($repo->commits()->pluck('sha')->all())->toBe([$recent]);
    expect($this->task->pullRequests()->pluck('number')->all())->toBe([3])
        ->and($this->task->branches()->pluck('name')->all())->toBe(['tp-1-login'])
        ->and($this->task->commits()->pluck('sha')->all())->toBe([$recent]);

    $commit = GitCommit::query()->sole();
    expect($commit->only(['message', 'author_name', 'author_username', 'url']))->toEqual([
        'message' => "TP-1 fix the form\n",
        'author_name' => 'Bikash Dev',
        'author_username' => 'bikash',
        'url' => "https://git.example/sdoc/app/commit/{$recent}",
    ])->and($commit->committed_at->equalTo($at))->toBeTrue();
    $pullRequest = GitPullRequest::query()->sole();
    expect($pullRequest->only(['title', 'state', 'url', 'head_branch', 'author_username']))->toEqual([
        'title' => 'TP-1 login',
        'state' => 'open',
        'url' => 'https://git.example/sdoc/app/pulls/3',
        'head_branch' => 'tp-1-login',
        'author_username' => 'bikash',
    ]);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/repos/sdoc/app/commits?') && repoQuery($r)['sha'] === 'main');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/repos/sdoc/app/pulls?') && repoQuery($r)['state'] === 'all');
});

it('backfills an open pull request however long it has been quiet', function () {
    $repo = repoLinked($this->project, $this->connection);
    repoFakeForge(['git.example/api/v1/repos/sdoc/app/pulls*' => repoPage([
        repoForgePullRequest(5, 'TP-1 slow review', now()->subDays(1), 'tp-1-slow'),
        repoForgePullRequest(1, 'TP-1 long-running', now()->subDays(120), 'tp-1-long'),
        repoForgePullRequest(2, 'TP-1 abandoned', now()->subDays(120), 'abandoned', ['state' => 'closed', 'closed_at' => now()->subDays(120)->toRfc3339String()]),
    ])]);

    BackfillGitRepository::dispatchSync($repo);

    expect($repo->pullRequests()->orderBy('number')->pluck('number')->all())->toBe([1, 5])
        ->and($this->task->pullRequests()->orderBy('number')->pluck('number')->all())->toBe([1, 5]);
});

it('keeps a pull request a webhook brought in when the backfill\'s snapshot of it is older', function () {
    $repo = repoLinked($this->project, $this->connection);
    $at = now()->subMinute()->startOfSecond();
    $merged = repoForgePullRequest(3, 'TP-1 login', $at, 'tp-1-login', ['state' => 'closed', 'merged' => true, 'merged_at' => $at->toRfc3339String(), 'closed_at' => $at->toRfc3339String()]);
    app(GitIngest::class)->pullRequest($repo, ForgeShapes::pullRequest($merged));
    repoFakeForge(['git.example/api/v1/repos/sdoc/app/pulls*' => repoPage([
        repoForgePullRequest(3, 'TP-1 login (draft)', now()->subHour(), 'tp-1-login'),
    ])]);

    BackfillGitRepository::dispatchSync($repo);

    $pullRequest = GitPullRequest::query()->sole();
    expect($pullRequest->only(['title', 'state']))->toBe(['title' => 'TP-1 login', 'state' => 'merged'])
        ->and($pullRequest->forge_updated_at?->equalTo($at))->toBeTrue();
});

it('skips the backfill of a repository unlinked before the job ran', function () {
    Http::fake();
    $repo = repoLinked($this->project, $this->connection);
    $job = new BackfillGitRepository($repo);
    GitRepository::query()->whereKey($repo->id)->delete();

    $job->handle(app(RepositorySync::class));

    Http::assertNothingSent();
});

it('stores a commit by someone without a forge account with no username', function () {
    $repo = repoLinked($this->project, $this->connection);
    repoFakeForge(['git.example/api/v1/repos/sdoc/app/commits*' => repoPage([repoForgeCommit('c1c2c3d4e5f60718293a4b5c6d7e8f9001122334', 'TP-1 fix', now()->subDay(), login: null)])]);

    BackfillGitRepository::dispatchSync($repo);

    expect(GitCommit::query()->sole()->author_username)->toBeNull();
});

it('backfills an empty repository without failing', function () {
    $repo = repoLinked($this->project, $this->connection);
    repoFakeForge(['git.example/api/v1/repos/sdoc/app/commits*' => Http::response(['message' => 'Git Repository is empty.'], 409)]);

    BackfillGitRepository::dispatchSync($repo);

    expect(GitCommit::query()->count())->toBe(0);
});

it('runs the backfill once the link has answered, on the sync queue', function () {
    repoFakeForge(['git.example/api/v1/repos/sdoc/app/commits*' => repoPage([repoForgeCommit('c1c2c3d4e5f60718293a4b5c6d7e8f9001122334', 'TP-1 fix', now()->subDay())])]);

    $this->actingAs(repoActor('manager', $this->project))
        ->post($this->base, ['full_name' => 'sdoc/app'])
        ->assertSessionHas('workspace_flash.message', 'Linked sdoc/app.');

    expect($this->task->commits()->count())->toBe(1);
});

it('credits linking and unlinking to whoever did it, and what the forge brings in to no one', function () {
    repoFakeForge(['git.example/api/v1/repos/sdoc/app/pulls*' => repoPage([repoForgePullRequest(3, 'TP-1 login', now()->subDay(), 'tp-1-login')])]);
    $manager = repoActor('manager', $this->project);

    // On the sync queue the backfill runs in the linking request, signed in.
    $this->actingAs($manager)->post($this->base, ['full_name' => 'sdoc/app'])->assertSessionHas('workspace_flash.message', 'Linked sdoc/app.');
    $repo = GitRepository::query()->sole();
    $merged = repoForgePullRequest(3, 'TP-1 login', now(), 'tp-1-login', ['state' => 'closed', 'merged' => true, 'merged_at' => now()->toRfc3339String(), 'closed_at' => now()->toRfc3339String()]);
    $this->actingAs($manager);
    app(GitIngest::class)->pullRequest($repo, ForgeShapes::pullRequest($merged));
    $this->delete("{$this->base}/{$repo->id}")->assertSessionHas('workspace_flash.success', true);

    expect(ProjectActivity::query()->whereIn('subject_type', ['git-repository', 'git-pull-request'])->orderBy('id')->get(['description', 'user_id'])->map(fn (ProjectActivity $a) => [$a->description, $a->user_id])->all())->toBe([
        ['Linked repository sdoc/app', $manager->id],
        ['Pull request #3 opened: TP-1 login', null],
        ['Pull request #3 merged', null],
        ['Unlinked repository sdoc/app', $manager->id],
    ]);
});

it('sets the last push from the newest commit the backfill brings in, never moving it back', function () {
    $repo = repoLinked($this->project, $this->connection);
    $newest = now()->subHours(2)->startOfSecond();
    repoFakeForge(['git.example/api/v1/repos/sdoc/app/commits*' => repoPage([
        repoForgeCommit('c1c2c3d4e5f60718293a4b5c6d7e8f9001122334', 'TP-1 newest', $newest),
        repoForgeCommit('d1d2c3d4e5f60718293a4b5c6d7e8f9001122334', 'TP-1 older', now()->subDays(3)),
    ])]);

    BackfillGitRepository::dispatchSync($repo);

    expect($repo->refresh()->last_pushed_at?->equalTo($newest))->toBeTrue();

    // A webhook since then saw a later push: the backfill leaves it.
    $later = now()->subMinutes(5)->startOfSecond();
    $repo->forceFill(['last_pushed_at' => $later])->save();

    BackfillGitRepository::dispatchSync($repo);

    expect($repo->refresh()->last_pushed_at?->equalTo($later))->toBeTrue();
});

it('gives the backfill five minutes, past a worker\'s default timeout', function () {
    expect((new BackfillGitRepository(repoLinked($this->project, $this->connection)))->timeout)->toBe(300);
});
