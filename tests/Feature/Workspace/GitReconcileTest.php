<?php

use App\Models\GitCommit;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\GitWebhookDelivery;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Task;
use App\Models\User;
use App\Services\Git\GitIngest;
use App\Services\Git\RepositorySync;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * The nightly `git:reconcile` and the forge owners sync, against a faked
 * Forgejo 15. Fixtures follow its API structs, as ProjectRepositoryTest's do.
 * Stray requests fail the test, so every forge call is one a stub expects.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com'], 'app.url' => 'https://tasks.example.test']);
    $this->travelTo(CarbonImmutable::parse('2026-10-29 01:30:00'));
    Http::preventStrayRequests();

    $this->organization = Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
    $this->connection = recConnection($this->organization, 'sdoc', 'git.example');
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Tax Portal', 'key' => 'TP']);
    $this->task = Task::factory()->for($this->project)->create();
    $this->repo = recLinked($this->project, $this->connection);
});

function recConnection(Organization $organization, string $owner, string $host): GitConnection
{
    return GitConnection::query()->create([
        'organization_id' => $organization->id,
        'base_url' => "https://{$host}",
        'owner' => $owner,
        'token' => "token-{$owner}-never-shown",
        'webhook_id' => 99,
        'webhook_secret' => "secret-{$owner}-never-shown",
    ]);
}

/** Linked and last reconciled a day ago, so the window opens two days ago. */
function recLinked(Project $project, GitConnection $connection, int $forgeId = 501, string $name = 'app'): GitRepository
{
    $host = (string) parse_url($connection->base_url, PHP_URL_HOST);

    return GitRepository::query()->create([
        'git_connection_id' => $connection->id,
        'project_id' => $project->id,
        'forge_id' => $forgeId,
        'owner' => $connection->owner,
        'name' => $name,
        'full_name' => "{$connection->owner}/{$name}",
        'html_url' => "https://{$host}/{$connection->owner}/{$name}",
        'clone_url' => "https://{$host}/{$connection->owner}/{$name}.git",
        'ssh_url' => "git@{$host}:{$connection->owner}/{$name}.git",
        'default_branch' => 'main',
        'reconciled_at' => now()->subDay(),
    ]);
}

/** @return array{0: User, 1: Member} */
function recPerson(Organization $organization, ?string $username, bool $officeAdmin = false, bool $active = true): array
{
    $user = User::factory()->create(['git_username' => $username]);
    $member = Member::factory()->linkedTo($user)->create([
        'organization_id' => $organization->id,
        'is_office_admin' => $officeAdmin,
        'is_active' => $active,
    ]);

    return [$user, $member];
}

function recSha(string $c): string
{
    return str_repeat($c, 40);
}

/** @return array<string, mixed> api.Repository */
function recForgeRepo(string $owner = 'sdoc', string $name = 'app', string $host = 'git.example'): array
{
    return [
        'id' => 501,
        'owner' => ['id' => 9, 'login' => $owner, 'username' => $owner],
        'name' => $name,
        'full_name' => "{$owner}/{$name}",
        'empty' => false,
        'private' => true,
        'html_url' => "https://{$host}/{$owner}/{$name}",
        'url' => "https://{$host}/api/v1/repos/{$owner}/{$name}",
        'ssh_url' => "git@{$host}:{$owner}/{$name}.git",
        'clone_url' => "https://{$host}/{$owner}/{$name}.git",
        'default_branch' => 'main',
        'archived' => false,
        'permissions' => ['admin' => true, 'push' => true, 'pull' => true],
    ];
}

/** @return array<string, mixed> api.Branch, its head an api.PayloadCommit */
function recForgeBranch(string $name, string $sha): array
{
    return [
        'name' => $name,
        'commit' => ['id' => $sha, 'message' => "Work on {$name}\n", 'url' => "https://git.example/sdoc/app/commit/{$sha}"],
        'protected' => false,
    ];
}

/** @return array<string, mixed> api.Commit as the list endpoint returns it */
function recForgeCommit(string $sha, string $message, CarbonInterface $at): array
{
    $date = $at->toRfc3339String();

    return [
        'sha' => $sha,
        'created' => $date,
        'html_url' => "https://git.example/sdoc/app/commit/{$sha}",
        'commit' => [
            'author' => ['name' => 'Bikash Dev', 'email' => 'bikash@example.com', 'date' => $date],
            'committer' => ['name' => 'Bikash Dev', 'email' => 'bikash@example.com', 'date' => $date],
            'message' => $message."\n",
        ],
        'author' => ['id' => 12, 'login' => 'bikash'],
        'committer' => ['id' => 12, 'login' => 'bikash'],
        'parents' => [],
    ];
}

/** @return array<string, mixed> api.PullRequest */
function recForgePullRequest(int $number, string $title, CarbonInterface $updatedAt): array
{
    return [
        'id' => 70 + $number,
        'number' => $number,
        'user' => ['id' => 12, 'login' => 'bikash'],
        'title' => $title,
        'body' => '',
        'state' => 'open',
        'html_url' => "https://git.example/sdoc/app/pulls/{$number}",
        'merged' => false,
        'merged_at' => null,
        'head' => ['label' => 'work', 'ref' => 'work'],
        'created_at' => $updatedAt->subDay()->toRfc3339String(),
        'updated_at' => $updatedAt->toRfc3339String(),
        'closed_at' => null,
    ];
}

/**
 * api.Hook as Forgejo 15 answers for the hook the tracker made, which never
 * shows the secret, with top-level fields replaced by $overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function recForgeHook(GitConnection $connection, array $overrides = []): array
{
    return array_merge([
        'id' => 99,
        'type' => 'forgejo',
        'branch_filter' => '*',
        'config' => ['url' => route('git.webhook', $connection), 'content_type' => 'json'],
        // Forgejo stores `pull_request` as itself and its sub-events.
        'events' => [
            'create', 'delete', 'push', 'pull_request', 'pull_request_assign', 'pull_request_label',
            'pull_request_milestone', 'pull_request_comment', 'pull_request_review_approved',
            'pull_request_review_rejected', 'pull_request_review_comment', 'pull_request_sync',
            'pull_request_review_request', 'repository',
        ],
        'authorization_header' => '',
        'active' => true,
        'updated_at' => '2026-10-28T10:00:00+05:45',
        'created_at' => '2026-10-28T10:00:00+05:45',
    ], $overrides);
}

/**
 * A list endpoint: the rows on the first page, nothing after it. Rows given
 * as a closure are read on each request, so a test can change them between
 * runs.
 *
 * @param  list<array<string, mixed>>|Closure(Request): list<array<string, mixed>>  $rows
 */
function recPage(array|Closure $rows): Closure
{
    return function (Request $request) use ($rows) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(($query['page'] ?? '1') === '1' ? ($rows instanceof Closure ? $rows($request) : $rows) : []);
    };
}

/** @param list<string> $logins */
function recLogins(array $logins): Closure
{
    return recPage(array_map(fn (string $login): array => ['login' => $login], $logins));
}

/**
 * One forge organization with one repository and an Owners team (id 5),
 * every list empty but the bot, except what $stubs replace.
 *
 * @param  array<string, mixed>  $stubs
 * @return array<string, mixed>
 */
function recForge(string $host = 'git.example', string $owner = 'sdoc', string $name = 'app', array $stubs = []): array
{
    $api = "{$host}/api/v1";
    $repo = "{$api}/repos/{$owner}/{$name}";

    return array_merge([
        // The bot, asked before any unlink whether it still owns the organization.
        "{$api}/user" => Http::response(['id' => 1, 'login' => "{$owner}-bot"]),
        "{$api}/users/{$owner}-bot/orgs/{$owner}/permissions" => Http::response(['is_owner' => true, 'is_admin' => true]),
        "{$api}/orgs/{$owner}/hooks/99" => fn () => Http::response(recForgeHook(GitConnection::query()->where('owner', $owner)->sole())),
        "{$api}/repositories/501" => Http::response(recForgeRepo($owner, $name, $host)),
        $repo => Http::response(recForgeRepo($owner, $name, $host)),
        "{$repo}/pulls?*" => recPage([]),
        "{$repo}/branches?*" => recPage([]),
        "{$repo}/commits?*" => recPage([]),
        // Each repository's access step: no collaborators, nobody wanted on a project without a team.
        "{$api}/repos/{$owner}/*/collaborators?*" => recPage([]),
        "{$api}/orgs/{$owner}/teams?*" => recPage([['id' => 4, 'name' => 'Readers'], ['id' => 5, 'name' => 'Owners']]),
        "{$api}/teams/5/members?*" => recLogins(["{$owner}-bot"]),
        "{$api}/teams/5/members/*" => Http::response('', 204),
        "{$api}/orgs/{$owner}/members?*" => recLogins(["{$owner}-bot"]),
        "{$api}/orgs/{$owner}/members/*" => Http::response('', 204),
    ], $stubs);
}

/**
 * The membership writes sent to one forge, as "PUT team asha" or "DELETE
 * org asha".
 *
 * @return list<string>
 */
function recMembershipWrites(string $host = 'git.example'): array
{
    return Http::recorded(fn (Request $r) => $r->method() !== 'GET' && str_starts_with($r->url(), "https://{$host}/"))
        ->map(function (array $pair): ?string {
            $url = $pair[0]->url();

            return match (true) {
                (bool) preg_match('#/teams/\d+/members/([^/?]+)$#', $url, $m) => $pair[0]->method().' team '.rawurldecode($m[1]),
                (bool) preg_match('#/orgs/[^/]+/members/([^/?]+)$#', $url, $m) => $pair[0]->method().' org '.rawurldecode($m[1]),
                default => null,
            };
        })
        ->filter()
        ->values()
        ->all();
}

function recDelivery(GitConnection $connection, CarbonInterface $receivedAt): GitWebhookDelivery
{
    return GitWebhookDelivery::query()->create([
        'git_connection_id' => $connection->id,
        'delivery' => (string) Str::uuid(),
        'event' => 'push',
        'payload' => ['ref' => 'refs/heads/main'],
        'received_at' => $receivedAt,
        'processed_at' => $receivedAt,
    ]);
}

// The webhook

it('recreates a missing webhook with a new secret', function () {
    Http::fake(recForge(stubs: [
        'git.example/api/v1/orgs/sdoc/hooks/99' => Http::response(['message' => 'Not Found'], 404),
        'git.example/api/v1/orgs/sdoc/hooks' => Http::response(['id' => 123], 201),
    ]));

    $this->artisan('git:reconcile')->doesntExpectOutputToContain('never-shown')->assertSuccessful();

    $connection = $this->connection->refresh();
    expect($connection->webhook_id)->toBe(123)
        ->and($connection->webhook_secret)->not->toBe('secret-sdoc-never-shown')->toHaveLength(40);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://git.example/api/v1/orgs/sdoc/hooks'
        && $r['config']['url'] === route('git.webhook', $connection)
        && $r['config']['secret'] === $connection->webhook_secret);
});

it('writes the stored secret back into a webhook that looks right, quietly, once a run', function () {
    // The forge never shows a hook's secret, so one changed there can't be seen.
    Log::spy();
    Http::fake(recForge());
    $hookWrites = fn () => Http::recorded(fn (Request $r) => $r->method() !== 'GET' && str_contains($r->url(), '/hooks'));

    $this->artisan('git:reconcile')
        ->expectsOutputToContain('sdoc at https://git.example: reconciled 1 repository.')
        ->doesntExpectOutputToContain('repaired')
        ->assertSuccessful();

    expect($hookWrites())->toHaveCount(1)
        ->and($this->connection->refresh()->webhook_secret)->toBe('secret-sdoc-never-shown');
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
        && $r->url() === 'https://git.example/api/v1/orgs/sdoc/hooks/99'
        && $r['config']['secret'] === 'secret-sdoc-never-shown');

    $this->artisan('git:reconcile')->assertSuccessful();

    expect($hookWrites())->toHaveCount(2)
        ->and($hookWrites()->every(fn (array $pair) => $pair[0]->method() === 'PATCH'))->toBeTrue();
    Log::shouldNotHaveReceived('warning', fn (string $message) => $message === "Put the forge webhook's settings back.");
});

it('puts back the settings of a webhook a forge owner changed, with the stored secret', function (string $change) {
    Log::spy();
    $hook = recForgeHook($this->connection, match ($change) {
        'disabled' => ['active' => false],
        'pointed elsewhere' => ['config' => ['url' => 'https://elsewhere.example/hook', 'content_type' => 'json']],
        'events trimmed' => ['events' => ['push', 'pull_request']],
        'sent as a form' => ['config' => ['url' => route('git.webhook', $this->connection), 'content_type' => 'form']],
        'one branch only' => ['branch_filter' => 'main'],
    });
    Http::fake(recForge(stubs: ['git.example/api/v1/orgs/sdoc/hooks/99' => Http::response($hook)]));

    $this->artisan('git:reconcile')
        ->expectsOutputToContain('sdoc at https://git.example: reconciled 1 repository, repaired the webhook.')
        ->doesntExpectOutputToContain('never-shown')
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
        && $r->url() === 'https://git.example/api/v1/orgs/sdoc/hooks/99'
        && $r->data() == [
            'active' => true,
            'branch_filter' => '*',
            'events' => GitWebhookDelivery::EVENTS,
            'config' => ['url' => route('git.webhook', $this->connection), 'content_type' => 'json', 'secret' => 'secret-sdoc-never-shown'],
        ]);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    expect($this->connection->refresh()->only(['webhook_id', 'webhook_secret']))->toBe(['webhook_id' => 99, 'webhook_secret' => 'secret-sdoc-never-shown']);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === "Put the forge webhook's settings back."
        && ! str_contains((string) json_encode($context), 'never-shown'))->once();
})->with(['disabled', 'pointed elsewhere', 'events trimmed', 'sent as a form', 'one branch only']);

// Repositories

it('fills in a pull request and a commit the webhooks missed, and points the forge back here', function () {
    $sha = recSha('c');
    $ancient = recSha('d');
    Http::fake(recForge(stubs: [
        'git.example/api/v1/repos/sdoc/app/pulls?*' => recPage([recForgePullRequest(3, 'TP-1 Add the login form', now()->subHours(3))]),
        'git.example/api/v1/repos/sdoc/app/branches?*' => recPage([recForgeBranch('main', $sha)]),
        'git.example/api/v1/repos/sdoc/app/commits?*' => recPage([
            recForgeCommit($sha, 'TP-1 Fix the form', now()->subHours(2)),
            recForgeCommit($ancient, 'TP-1 Older than the window', now()->subDays(5)),
        ]),
    ]));

    $this->artisan('git:reconcile')->assertSuccessful();

    expect($this->task->pullRequests()->pluck('number')->all())->toBe([3])
        ->and($this->task->commits()->pluck('sha')->all())->toBe([$sha])
        ->and($this->repo->refresh()->reconciled_at?->equalTo(now()))->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
        && $r->url() === 'https://git.example/api/v1/repos/sdoc/app'
        && $r['external_tracker']['external_tracker_format'] === 'https://tasks.example.test/workspace/go/{user}/{index}');
});

it('catches a newly linked repository up on the last 90 days', function () {
    $this->repo->forceFill(['reconciled_at' => null])->save();
    $sha = recSha('c');
    Http::fake(recForge(stubs: [
        'git.example/api/v1/repos/sdoc/app/branches?*' => recPage([recForgeBranch('main', $sha)]),
        'git.example/api/v1/repos/sdoc/app/commits?*' => recPage([recForgeCommit($sha, 'TP-1 Fix the form', now()->subDays(30))]),
    ]));

    $this->artisan('git:reconcile')->assertSuccessful();

    expect($this->task->commits()->pluck('sha')->all())->toBe([$sha]);
});

it('marks commits a force-push left behind unreachable, keeps them, and clears the mark when they come back', function () {
    [$a, $b, $c, $before, $root] = [recSha('a'), recSha('b'), recSha('c'), recSha('e'), recSha('f')];
    $ingest = app(GitIngest::class);
    $store = fn (string $sha, CarbonInterface $at) => $ingest->commit($this->repo, [
        'sha' => $sha, 'message' => 'TP-1 step', 'author_name' => 'Bikash Dev', 'author_username' => 'bikash',
        'url' => "https://git.example/sdoc/app/commit/{$sha}", 'committed_at' => $at->toRfc3339String(),
    ]);
    $store($a, now()->subHours(5));
    $store($b, now()->subHours(4));
    $store($c, now()->subHours(3));
    $store($before, now()->subDays(10)); // older than the window: never judged

    // main was force-pushed back to $a; $b and $c are on no branch.
    $history = [
        recForgeCommit($a, 'TP-1 step', now()->subHours(5)),
        recForgeCommit($root, 'Start', now()->subDays(30)),
    ];
    Http::fake(recForge(stubs: [
        'git.example/api/v1/repos/sdoc/app/branches?*' => recPage([recForgeBranch('main', $a), recForgeBranch('TP-1-docs', $a)]),
        'git.example/api/v1/repos/sdoc/app/commits?*' => recPage(function () use (&$history) {
            return $history;
        }),
    ]));

    $this->artisan('git:reconcile')->assertSuccessful();

    $unreachable = fn () => GitCommit::query()->whereNotNull('unreachable_at')->orderBy('sha')->pluck('sha')->all();
    expect($unreachable())->toBe([$b, $c])
        ->and(GitCommit::query()->where('sha', $b)->value('unreachable_at'))->toEqual(now()->toDateTimeString())
        ->and(GitCommit::query()->count())->toBe(4)
        ->and($this->task->commits()->count())->toBe(4);

    // Someone pushes the old head back the next day.
    $this->travel(1)->days();
    $history = [
        recForgeCommit($c, 'TP-1 step', now()->subDay()->subHours(3)),
        recForgeCommit($b, 'TP-1 step', now()->subDay()->subHours(4)),
        recForgeCommit($a, 'TP-1 step', now()->subDay()->subHours(5)),
        recForgeCommit($root, 'Start', now()->subDays(31)),
    ];

    $this->artisan('git:reconcile')->assertSuccessful();

    expect($unreachable())->toBe([]);
});

it('marks nothing when a branch has more history in the window than it can list', function () {
    $lost = recSha('b');
    app(GitIngest::class)->commit($this->repo, [
        'sha' => $lost, 'message' => 'TP-1 step', 'author_name' => 'Bikash Dev', 'author_username' => 'bikash',
        'url' => "https://git.example/sdoc/app/commit/{$lost}", 'committed_at' => now()->subHours(20)->toRfc3339String(),
    ]);
    // Page after page of recent commits, none of them $lost, with no end in sight.
    Http::fake(recForge(stubs: [
        'git.example/api/v1/repos/sdoc/app/branches?*' => recPage([recForgeBranch('main', recSha('a'))]),
        'git.example/api/v1/repos/sdoc/app/commits?*' => function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $page = (int) $q['page'];

            return Http::response(array_map(
                fn (int $i) => recForgeCommit(str_pad(dechex($page * 100 + $i), 40, '0', STR_PAD_LEFT), 'Busy', now()->subMinutes($page * 50 + $i)),
                range(0, 49),
            ));
        },
    ]));

    $this->artisan('git:reconcile')->assertSuccessful();

    expect(GitCommit::query()->where('sha', $lost)->value('unreachable_at'))->toBeNull();
});

it('prunes a branch the forge no longer has, and keeps one made while it ran', function () {
    $ingest = app(GitIngest::class);
    $ingest->branch($this->repo, 'main', recSha('a'));
    $ingest->branch($this->repo, 'TP-1-gone', recSha('b'));
    $this->travel(1)->minutes();
    $ingest->branch($this->repo, 'TP-1-fresh', recSha('c')); // a webhook, the moment the run starts
    Http::fake(recForge(stubs: [
        'git.example/api/v1/repos/sdoc/app/branches?*' => recPage([recForgeBranch('main', recSha('a'))]),
    ]));

    $this->artisan('git:reconcile')->assertSuccessful();

    expect($this->repo->branches()->orderBy('name')->pluck('name')->all())->toBe(['TP-1-fresh', 'main'])
        ->and($this->task->branches()->pluck('name')->all())->toBe(['TP-1-fresh']);
});

it('prunes no branch when the forge has more than it lists', function () {
    app(GitIngest::class)->branch($this->repo, 'TP-1-gone', recSha('b'));
    $this->travel(1)->minutes();
    Http::fake(recForge(stubs: [
        'git.example/api/v1/repos/sdoc/app/branches?*' => function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return Http::response(array_map(fn (int $i) => recForgeBranch("work-{$q['page']}-{$i}", recSha('a')), range(1, 50)));
        },
    ]));

    $this->artisan('git:reconcile')->assertSuccessful();

    expect($this->repo->branches()->where('name', 'TP-1-gone')->exists())->toBeTrue();
});

it('unlinks a repository the forge no longer has, or that moved to another owner', function (int $status, string $owner, string $why) {
    Log::spy();
    Http::fake(recForge(stubs: [
        'git.example/api/v1/repositories/501' => Http::response($status === 404 ? ['message' => 'Not Found'] : recForgeRepo($owner), $status),
    ]));

    $this->artisan('git:reconcile')
        ->expectsOutputToContain('sdoc at https://git.example: reconciled 0 repositories, unlinked 1.')
        ->assertSuccessful();

    expect(GitRepository::query()->count())->toBe(0)
        ->and(ProjectActivity::query()->where('description', 'Unlinked repository sdoc/app')->exists())->toBeTrue();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/repos/'));
    // Asked once whether the bot still owns sdoc, before unlinking.
    Http::assertSent(fn (Request $r) => $r->url() === 'https://git.example/api/v1/users/sdoc-bot/orgs/sdoc/permissions');
    Log::shouldHaveReceived('warning')->with('Unlinked a repository the forge organization no longer has.', ['repository' => 'sdoc/app', 'why' => $why])->once();
})->with([
    'deleted' => [404, 'sdoc', 'gone from the forge'],
    'transferred' => [200, 'elsewhere', 'moved to another owner'],
]);

it('unlinks nothing and exits non-zero when the bot no longer owns the forge organization', function (string $how) {
    Log::spy();
    recLinked($this->project, $this->connection, 502, 'api');
    recLinked($this->project, $this->connection, 503, 'web');
    $permissions = 'git.example/api/v1/users/sdoc-bot/orgs/sdoc/permissions';
    // Every repository looks gone, or moved: what a bot that lost the organization sees.
    $lost = $how === 'organization renamed'
        ? fn (Request $r) => Http::response([...recForgeRepo('sdoc-renamed'), 'id' => (int) basename($r->url())])
        : Http::response(['message' => 'Not Found'], 404);
    Http::fake(recForge(stubs: [
        'git.example/api/v1/repositories/501' => $lost,
        'git.example/api/v1/repositories/502' => $lost,
        'git.example/api/v1/repositories/503' => $lost,
        $permissions => match ($how) {
            'taken off the Owners' => Http::response(['is_owner' => false, 'is_admin' => false]),
            'organization deleted' => Http::response(['message' => 'Not Found'], 404),
            // Forgejo redirects an old name to the new one; that is not the organization connected.
            'organization renamed' => Http::response('', 307, ['Location' => '/api/v1/users/sdoc-bot/orgs/sdoc-renamed/permissions']),
        },
        'git.example/api/v1/users/sdoc-bot/orgs/sdoc-renamed/permissions' => Http::response(['is_owner' => true]),
    ]));

    $this->artisan('git:reconcile')
        ->expectsOutputToContain('sdoc at https://git.example: failed (unlinks: Held back: the bot no longer owns sdoc; check the Organizations page); reconciled 0 repositories.')
        ->assertFailed();

    expect(GitRepository::query()->count())->toBe(3)
        ->and(ProjectActivity::query()->where('description', 'like', 'Unlinked repository%')->exists())->toBeFalse();
    Log::shouldHaveReceived('warning')->with('Held back repository unlinks: the bot no longer owns sdoc; check the Organizations page.', ['owner' => 'sdoc', 'login' => 'sdoc-bot', 'unlinking' => 3])->once();
})->with(['taken off the Owners', 'organization deleted', 'organization renamed']);

it('holds back every unlink when too many would go at once', function (int $linked, int $gone, bool $held) {
    Log::spy();
    $this->repo->delete();
    $stubs = ['git.example/api/v1/repos/sdoc/*' => recPage([])];
    foreach (range(1, $linked) as $i) {
        recLinked($this->project, $this->connection, 600 + $i, "r{$i}");
        $stubs['git.example/api/v1/repositories/'.(600 + $i)] = $i <= $gone
            ? Http::response(['message' => 'Not Found'], 404)
            : Http::response([...recForgeRepo('sdoc', "r{$i}"), 'id' => 600 + $i]);
    }
    Http::fake(recForge(stubs: $stubs));

    $run = $this->artisan('git:reconcile');

    if ($held) {
        $run->expectsOutputToContain("unlinks: Held back: it would unlink {$gone} of the {$linked} linked repositories")->assertFailed()->run();
        expect(GitRepository::query()->count())->toBe($linked);
        Log::shouldHaveReceived('warning')->with('Held back repository unlinks: too many at once.', ['owner' => 'sdoc', 'unlinking' => $gone, 'linked' => $linked])->once();
    } else {
        $run->expectsOutputToContain("unlinked {$gone}")->assertSuccessful()->run();
        expect(GitRepository::query()->count())->toBe($linked - $gone);
    }
})->with([
    'four of four' => [4, 4, true],
    'three, the floor' => [3, 3, false],
    'four of 16, a quarter' => [16, 4, false],
    'five of 16' => [16, 5, true],
]);

it('lifts both breakers for one run with --allow-removals, and says so', function () {
    $this->repo->delete();
    $stubs = ['git.example/api/v1/repos/sdoc/*' => recPage([])];
    foreach (range(1, 4) as $i) {
        recLinked($this->project, $this->connection, 600 + $i, "r{$i}");
        $stubs['git.example/api/v1/repositories/'.(600 + $i)] = Http::response(['message' => 'Not Found'], 404);
    }
    $logins = [];
    foreach (range(1, 6) as $i) {
        recPerson($this->organization, "person-{$i}", active: false);
        $logins[] = "person-{$i}";
    }
    $stubs['git.example/api/v1/orgs/sdoc/members?*'] = recLogins(['sdoc-bot', ...$logins]);
    Http::fake(recForge(stubs: $stubs));

    $this->artisan('git:reconcile', ['--allow-removals' => true])
        ->expectsOutputToContain('Removal breakers lifted for this run (--allow-removals).')
        ->expectsOutputToContain('sdoc at https://git.example: reconciled 0 repositories, unlinked 4.')
        ->assertSuccessful();

    expect(GitRepository::query()->count())->toBe(0)
        ->and(recMembershipWrites())->toHaveCount(6)->each->toStartWith('DELETE org person-');
});

it('still asks whether the bot owns the organization with --allow-removals', function () {
    Http::fake(recForge(stubs: [
        'git.example/api/v1/repositories/501' => Http::response(['message' => 'Not Found'], 404),
        'git.example/api/v1/users/sdoc-bot/orgs/sdoc/permissions' => Http::response(['is_owner' => false]),
    ]));

    $this->artisan('git:reconcile', ['--allow-removals' => true])
        ->expectsOutputToContain('unlinks: Held back: the bot no longer owns sdoc; check the Organizations page')
        ->assertFailed();

    expect(GitRepository::query()->count())->toBe(1);
});

it('follows a rename on the forge', function () {
    Http::fake(recForge(name: 'portal'));

    $this->artisan('git:reconcile')->assertSuccessful();

    expect($this->repo->refresh()->only(['name', 'full_name', 'html_url', 'clone_url', 'ssh_url']))->toBe([
        'name' => 'portal',
        'full_name' => 'sdoc/portal',
        'html_url' => 'https://git.example/sdoc/portal',
        'clone_url' => 'https://git.example/sdoc/portal.git',
        'ssh_url' => 'git@git.example:sdoc/portal.git',
    ]);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === 'https://git.example/api/v1/repos/sdoc/portal');
});

// Forge membership

it('keeps the Owners team in step with the office admins, and never touches the bots, forge-admin or strangers', function () {
    recPerson($this->organization, 'asha', officeAdmin: true);              // new office admin
    recPerson($this->organization, 'bina');                                 // demoted, still an owner
    recPerson($this->organization, 'chet', officeAdmin: true);              // already an owner
    recPerson($this->organization, 'dipa', officeAdmin: true, active: false); // deactivated
    recPerson($this->organization, null, officeAdmin: true);                // never used the forge
    recPerson(Organization::factory()->create(), 'esha', officeAdmin: true); // another office's admin
    // Never handed out by GitUsernames, but untouched even if a person carried them.
    recPerson($this->organization, 'sdoc-bot');
    recPerson($this->organization, 'forge-admin');
    recConnection(Organization::factory()->create(), 'opmcm', 'git.other');
    recPerson($this->organization, 'opmcm-bot');

    Http::fake([
        ...recForge(stubs: [
            'git.example/api/v1/teams/5/members?*' => recLogins(['sdoc-bot', 'forge-admin', 'opmcm-bot', 'BINA', 'chet', 'dipa', 'made-by-hand']),
        ]),
        ...recForge('git.other', 'opmcm'),
    ]);

    Log::spy();

    $this->artisan('git:reconcile')->assertSuccessful();

    expect(recMembershipWrites())->toEqualCanonicalizing(['PUT team asha', 'DELETE team BINA', 'DELETE team dipa']);
    // Production logs from warning up.
    Log::shouldHaveReceived('warning')->with('Made an office admin a forge owner.', ['owner' => 'sdoc', 'login' => 'asha'])->once();
    Log::shouldHaveReceived('warning')->with('Took someone who is not an office admin off the forge owners.', ['owner' => 'sdoc', 'login' => 'BINA'])->once();
    Log::shouldHaveReceived('warning')->with('Took someone who is not an office admin off the forge owners.', ['owner' => 'sdoc', 'login' => 'dipa'])->once();
});

it('skips an office admin the forge has no account for', function () {
    recPerson($this->organization, 'asha', officeAdmin: true);
    recPerson($this->organization, 'bina', officeAdmin: true);
    Http::fake(recForge(stubs: [
        'git.example/api/v1/teams/5/members/asha' => Http::response(['message' => 'user does not exist'], 404),
    ]));

    $this->artisan('git:reconcile')->assertSuccessful();

    expect(recMembershipWrites())->toEqualCanonicalizing(['PUT team asha', 'PUT team bina']);
});

it('removes from a forge organization the people the tracker no longer lets in', function () {
    $opmcm = Organization::factory()->create(['name' => 'OPMCM', 'slug' => 'opmcm']);
    recConnection($opmcm, 'opmcm', 'git.other');
    $team = Project::factory()->create(['organization_id' => $opmcm->id, 'key' => 'OP'])->ensureTeam();
    $team->update(['is_cross_organization' => true]);
    [, $ram] = recPerson($this->organization, 'ram.k');
    [, $sita] = recPerson($this->organization, 'sita');
    recPerson($opmcm, 'hari');
    recPerson($opmcm, 'gita', active: false);
    recPerson($opmcm, 'forge-admin', active: false);
    recPerson($opmcm, 'opmcm-bot', active: false);
    User::factory()->create(['email' => 'boss@example.com', 'git_username' => 'boss']);
    $team->members()->attach([$ram->id, $sita->id]);
    $team->members()->detach($ram->id);

    Http::fake([
        ...recForge(),
        ...recForge('git.other', 'opmcm', stubs: [
            'git.other/api/v1/orgs/opmcm/members?*' => recLogins(['opmcm-bot', 'forge-admin', 'Ram.K', 'sita', 'hari', 'gita', 'boss', 'made-by-hand']),
        ]),
    ]);

    Log::spy();

    $this->artisan('git:reconcile')->assertSuccessful();

    expect(recMembershipWrites('git.other'))->toEqualCanonicalizing(['DELETE org Ram.K', 'DELETE org gita'])
        ->and(recMembershipWrites('git.example'))->toBe([]);
    Log::shouldHaveReceived('warning')->with('Took someone the tracker no longer lets in off the forge organization.', ['owner' => 'opmcm', 'login' => 'Ram.K'])->once();
    Log::shouldHaveReceived('warning')->with('Took someone the tracker no longer lets in off the forge organization.', ['owner' => 'opmcm', 'login' => 'gita'])->once();
});

it('removes a person whose member was deleted while their login stayed', function () {
    [, $tara] = recPerson($this->organization, 'tara');
    $tara->delete();
    Http::fake(recForge(stubs: ['git.example/api/v1/orgs/sdoc/members?*' => recLogins(['sdoc-bot', 'tara'])]));

    $this->artisan('git:reconcile')->assertSuccessful();

    expect(recMembershipWrites())->toBe(['DELETE org tara']);
});

it('takes a super-admin off the Owners team but leaves them in the organization', function () {
    User::factory()->create(['email' => 'boss@example.com', 'git_username' => 'boss']);
    Http::fake(recForge(stubs: [
        'git.example/api/v1/teams/5/members?*' => recLogins(['sdoc-bot', 'boss']),
        'git.example/api/v1/orgs/sdoc/members?*' => recLogins(['sdoc-bot', 'boss']),
    ]));

    $this->artisan('git:reconcile')->assertSuccessful();

    expect(recMembershipWrites())->toBe(['DELETE team boss']);
});

it('holds back every owner removal when too many would go at once', function (int $known, int $leaving, bool $held) {
    $logins = [];
    foreach (range(1, $known) as $i) {
        recPerson($this->organization, "owner-{$i}", officeAdmin: $i > $leaving);
        $logins[] = "owner-{$i}";
    }
    Http::fake(recForge(stubs: ['git.example/api/v1/teams/5/members?*' => recLogins(['sdoc-bot', ...$logins])]));

    $run = $this->artisan('git:reconcile');

    if ($held) {
        $run->expectsOutputToContain("owners: Held back: it would remove {$leaving} of the {$known} people it knows there")->assertFailed()->run();
        expect(recMembershipWrites())->toBe([]);
    } else {
        $run->assertSuccessful()->run();
        expect(recMembershipWrites())->toHaveCount($leaving)->each->toStartWith('DELETE team owner-');
    }
})->with([
    'six of six' => [6, 6, true],
    'five, the floor' => [5, 5, false],
    'six of 24, a quarter' => [24, 6, false],
    'seven of 24' => [24, 7, true],
]);

it('holds back every member removal when too many would go at once', function (int $known, int $leaving, bool $held) {
    Log::spy();
    $logins = [];
    foreach (range(1, $known) as $i) {
        recPerson($this->organization, "person-{$i}", active: $i > $leaving);
        $logins[] = "person-{$i}";
    }
    Http::fake(recForge(stubs: ['git.example/api/v1/orgs/sdoc/members?*' => recLogins(['sdoc-bot', ...$logins])]));

    $run = $this->artisan('git:reconcile');

    if ($held) {
        $run->expectsOutputToContain("members: Held back: it would remove {$leaving} of the {$known} people it knows there")->assertFailed()->run();
        expect(recMembershipWrites())->toBe([]);
        Log::shouldHaveReceived('warning')->with('Held back forge removals: too many at once.', ['owner' => 'sdoc', 'from' => 'members', 'removing' => $leaving, 'known' => $known])->once();
    } else {
        $run->assertSuccessful()->run();
        expect(recMembershipWrites())->toHaveCount($leaving)->each->toStartWith('DELETE org person-');
    }
})->with([
    'six of six' => [6, 6, true],
    'five, the floor' => [5, 5, false],
    'six of 24, a quarter' => [24, 6, false],
    'seven of 24' => [24, 7, true],
]);

// Failures

it('carries on past a failing connection, says so without secrets, and exits non-zero', function (string $failure) {
    Log::spy();
    $opmcm = Organization::factory()->create(['name' => 'OPMCM', 'slug' => 'opmcm']);
    $other = recConnection($opmcm, 'opmcm', 'git.other');
    $otherRepo = recLinked(Project::factory()->create(['organization_id' => $opmcm->id, 'key' => 'OP']), $other);

    Http::fake([
        'git.example/*' => $failure === 'unreachable'
            ? fn () => throw new ConnectionException('cURL error 7: Failed to connect token-sdoc-never-shown')
            : Http::response(['message' => 'boom'], 500),
        ...recForge('git.other', 'opmcm'),
    ]);

    $this->artisan('git:reconcile')
        ->expectsOutputToContain('sdoc at https://git.example: failed')
        ->expectsOutputToContain('opmcm at https://git.other: reconciled 1 repository')
        ->doesntExpectOutputToContain('never-shown')
        ->assertFailed();

    expect($otherRepo->refresh()->reconciled_at?->equalTo(now()))->toBeTrue()
        ->and($this->repo->refresh()->reconciled_at?->equalTo(now()->subDay()))->toBeTrue();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => ($context['owner'] ?? null) === 'sdoc'
        && ! str_contains((string) json_encode($context), 'never-shown'))->atLeast()->once();
    Log::shouldNotHaveReceived('warning', fn (string $message, array $context = []) => str_contains((string) json_encode($context), 'never-shown'));
})->with(['server error', 'unreachable']);

it('keeps going past one failing repository and still syncs the members', function () {
    recLinked($this->project, $this->connection, 502, 'api');
    recPerson($this->organization, 'asha', officeAdmin: true);
    Http::fake(recForge(stubs: [
        'git.example/api/v1/repositories/502' => Http::response(['message' => 'boom'], 500),
    ]));

    $this->artisan('git:reconcile')
        ->expectsOutputToContain('sdoc at https://git.example: failed (sdoc/api: The forge answered 500); reconciled 1 repository.')
        ->assertFailed();

    expect($this->repo->refresh()->reconciled_at?->equalTo(now()))->toBeTrue()
        ->and(recMembershipWrites())->toBe(['PUT team asha']);
});

it('reports an unexpected failure by its class alone, and still syncs the members', function () {
    Exceptions::fake();
    recPerson($this->organization, 'asha', officeAdmin: true);
    Http::fake(recForge());
    $this->mock(RepositorySync::class)->shouldReceive('reconcile')
        ->andThrow(new RuntimeException('insert into git_commits values (token-sdoc-never-shown)'));

    $this->artisan('git:reconcile')
        ->expectsOutputToContain('sdoc at https://git.example: failed (sdoc/app: RuntimeException); reconciled 0 repositories.')
        ->doesntExpectOutputToContain('never-shown')
        ->assertFailed();

    expect(recMembershipWrites())->toBe(['PUT team asha']);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'The git reconcile of sdoc failed (sdoc/app): RuntimeException.');
    Exceptions::assertNotReported(fn (Throwable $e) => str_contains($e->getMessage(), 'never-shown'));
});

it('reconciles only the connection asked for', function () {
    $opmcm = Organization::factory()->create(['name' => 'OPMCM', 'slug' => 'opmcm']);
    recConnection($opmcm, 'opmcm', 'git.other');
    Http::fake(recForge());

    $this->artisan('git:reconcile', ['--connection' => $this->connection->id])
        ->expectsOutputToContain('sdoc at https://git.example: reconciled 1 repository')
        ->doesntExpectOutputToContain('opmcm')
        ->assertSuccessful();
});

// Housekeeping

it('deletes deliveries older than 14 days', function () {
    Http::fake(recForge());
    recDelivery($this->connection, now()->subDays(15));
    $recent = recDelivery($this->connection, now()->subDays(13));

    $this->artisan('git:reconcile')->assertSuccessful();

    expect(GitWebhookDelivery::query()->pluck('id')->all())->toBe([$recent->id]);
});

it('runs every night at 01:30, one at a time', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'git:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 1 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

// The office-admin flag

/** @param array<string, mixed> $data */
function recUpdateMember(string $surface, Member $member, array $data): void
{
    $boss = User::query()->where('email', 'boss@example.com')->first() ?? User::factory()->create(['email' => 'boss@example.com']);

    if ($surface === 'web') {
        test()->actingAs($boss)->patch("/workspace/members/{$member->id}", $data)
            ->assertSessionHas('workspace_flash.message', 'Member updated.');

        return;
    }

    Sanctum::actingAs($boss);
    test()->patchJson("/api/v1/workspace/members/{$member->id}", $data)
        ->assertOk()
        ->assertJsonPath('message', 'Member updated.');
}

it('syncs the Owners team when the office-admin flag changes', function (string $surface) {
    Http::fake(recForge(stubs: ['git.example/api/v1/teams/5/members?*' => recLogins(['sdoc-bot', 'bina'])]));
    [, $asha] = recPerson($this->organization, 'asha');
    [, $bina] = recPerson($this->organization, 'bina', officeAdmin: true);

    recUpdateMember($surface, $asha, ['is_office_admin' => true]);

    expect(recMembershipWrites())->toBe(['PUT team asha']);

    recUpdateMember($surface, $bina, ['is_office_admin' => false]);

    expect(recMembershipWrites())->toContain('DELETE team bina');
})->with(['web', 'api']);

it('moves an office admin\'s forge ownership with them to another organization', function (string $surface) {
    $opmcm = Organization::factory()->create(['name' => 'OPMCM', 'slug' => 'opmcm']);
    recConnection($opmcm, 'opmcm', 'git.other');
    [, $asha] = recPerson($this->organization, 'asha', officeAdmin: true);
    Http::fake([
        ...recForge(stubs: ['git.example/api/v1/teams/5/members?*' => recLogins(['sdoc-bot', 'asha'])]),
        ...recForge('git.other', 'opmcm'),
    ]);

    recUpdateMember($surface, $asha, ['organization_id' => $opmcm->id]);

    expect(recMembershipWrites())->toBe(['DELETE team asha'])
        ->and(recMembershipWrites('git.other'))->toBe(['PUT team asha']);
})->with(['web', 'api']);

it('gives a reactivated office admin their forge ownership back', function (string $surface) {
    Http::fake(recForge());
    [, $asha] = recPerson($this->organization, 'asha', officeAdmin: true, active: false);

    recUpdateMember($surface, $asha, ['is_active' => true]);

    expect(recMembershipWrites())->toBe(['PUT team asha']);
})->with(['web', 'api']);

it('makes no forge call when the flag stays as it was', function (string $surface) {
    Http::fake(recForge());
    [, $asha] = recPerson($this->organization, 'asha', officeAdmin: true);

    recUpdateMember($surface, $asha, ['name' => 'Asha Director', 'is_office_admin' => true]);

    Http::assertNothingSent();
})->with(['web', 'api']);

it('saves the flag even when the forge fails, and logs it without the token', function (string $surface) {
    Log::spy();
    Http::fake(['git.example/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect token-sdoc-never-shown')]);
    [, $asha] = recPerson($this->organization, 'asha');

    recUpdateMember($surface, $asha, ['is_office_admin' => true]);

    expect($asha->refresh()->is_office_admin)->toBeTrue();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => ($context['owner'] ?? null) === 'sdoc'
        && str_contains((string) ($context['cause'] ?? ''), '[token]')
        && ! str_contains((string) json_encode($context), 'never-shown'))->once();
})->with(['web', 'api']);
