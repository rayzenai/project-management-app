<?php

use App\Models\GitBranch;
use App\Models\GitCommit;
use App\Models\GitConnection;
use App\Models\GitPullRequest;
use App\Models\GitRepository;
use App\Models\Organization;
use App\Models\Project;
use App\Queries\MainChecksQuery;
use App\Services\Git\CommitChecks;
use App\Services\Git\GitIngest;
use App\Support\Git\Checks;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Checks: the commit statuses Forgejo Actions writes for each job, read from
 * a faked Forgejo 15 (GET repos/{owner}/{repo}/commits/{sha}/status, an
 * api.CombinedStatus). Stray requests fail the test.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->organization = Organization::factory()->create();
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'key' => 'TP']);
    $this->connection = GitConnection::query()->create([
        'organization_id' => $this->organization->id,
        'base_url' => 'https://git.example',
        'owner' => 'sdoc',
        'token' => 'token-sdoc-never-shown',
        'webhook_id' => 99,
        'webhook_secret' => 'secret-never-shown',
    ]);
    $this->repo = checksRepository($this->project, $this->connection, 501, 'app');
});

function checksRepository(Project $project, GitConnection $connection, int $forgeId, string $name): GitRepository
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

function checksCommit(GitRepository $repo, string $sha, ?CarbonInterface $learnedAt = null): GitCommit
{
    $commit = $repo->commits()->create([
        'sha' => $sha,
        'message' => 'Work',
        'author_name' => 'Asha',
        'author_username' => 'asha',
        'url' => "https://git.example/sdoc/app/commit/{$sha}",
        'committed_at' => now()->subDays(5),
    ]);

    if ($learnedAt !== null) {
        $commit->forceFill(['created_at' => $learnedAt])->save();
    }

    return $commit;
}

function checksPullRequest(GitRepository $repo, int $number, string $headSha): GitPullRequest
{
    return $repo->pullRequests()->create([
        'number' => $number,
        'title' => "Change {$number}",
        'state' => 'open',
        'url' => "https://git.example/sdoc/app/pulls/{$number}",
        'head_branch' => "work-{$number}",
        'head_sha' => $headSha,
        'opened_at' => now(),
    ]);
}

/** @return array<string, mixed> api.CommitStatus */
function checksStatus(string $context, string $state, string $url = ''): array
{
    return [
        'id' => crc32($context),
        'status' => $state,
        'target_url' => $url,
        'description' => '',
        'url' => '',
        'context' => $context,
        'creator' => null,
        'created_at' => '2026-10-31T10:00:00+05:45',
        'updated_at' => '2026-10-31T10:00:00+05:45',
    ];
}

/**
 * @param  list<array<string, mixed>>  $statuses
 * @return array<string, mixed> api.CombinedStatus (its own `state` is the forge's; the tracker works it out from the statuses)
 */
function checksCombined(string $sha, array $statuses): array
{
    return ['state' => 'pending', 'sha' => $sha, 'total_count' => count($statuses), 'statuses' => $statuses, 'repository' => null, 'commit_url' => '', 'url' => ''];
}

function checksFake(string $sha, array $statuses, string $repo = 'app'): void
{
    Http::fake(["git.example/api/v1/repos/sdoc/{$repo}/commits/{$sha}/status*" => Http::response(checksCombined($sha, $statuses))]);
}

// Reading the forge's statuses

it('passes only success, runs pending and fails the rest, sorted by name', function () {
    $read = Checks::fromCombined(checksCombined('aaa', [
        checksStatus('tests / pest (push)', 'success', 'https://git.example/sdoc/app/actions/runs/4/jobs/0'),
        checksStatus('lint / pint (push)', 'pending'),
        checksStatus('build (push)', 'warning'),
        checksStatus('audit (push)', 'error'),
        checksStatus('e2e (push)', 'failure'),
    ]));

    expect($read['state'])->toBe(Checks::FAILED)
        ->and($read['checks'])->toEqual([
            ['name' => 'audit (push)', 'state' => 'failed', 'url' => null],
            ['name' => 'build (push)', 'state' => 'failed', 'url' => null],
            ['name' => 'e2e (push)', 'state' => 'failed', 'url' => null],
            ['name' => 'lint / pint (push)', 'state' => 'running', 'url' => null],
            ['name' => 'tests / pest (push)', 'state' => 'passed', 'url' => 'https://git.example/sdoc/app/actions/runs/4/jobs/0'],
        ]);
});

it('says running while one runs and none failed, passed when all passed, and none for no checks', function () {
    $state = fn (array $statuses): string => Checks::fromCombined(checksCombined('aaa', $statuses))['state'];

    expect($state([checksStatus('a', 'success'), checksStatus('b', 'pending')]))->toBe(Checks::RUNNING)
        ->and($state([checksStatus('a', 'success'), checksStatus('b', 'success')]))->toBe(Checks::PASSED)
        ->and(Checks::fromCombined(checksCombined('aaa', [])))->toBe(['state' => Checks::NONE, 'checks' => []])
        ->and(Checks::fromCombined([]))->toBe(['state' => Checks::NONE, 'checks' => []]);
});

it('carries checks to the pages only once the tracker has asked', function () {
    expect(Checks::shape(null, null))->toBeNull()
        ->and(Checks::shape(Checks::NONE, null))->toBe(['state' => 'none', 'checks' => []]);
});

// CommitChecks

it("keeps a commit's checks on it and on its repository's pull requests whose head it is", function () {
    $commit = checksCommit($this->repo, 'aaa');
    $other = checksCommit($this->repo, 'bbb');
    $pr = checksPullRequest($this->repo, 3, 'aaa');
    $otherPr = checksPullRequest($this->repo, 4, 'bbb');
    $elsewhere = checksPullRequest(checksRepository($this->project, $this->connection, 502, 'web'), 3, 'aaa');
    checksFake('aaa', [checksStatus('tests / pest (push)', 'failure', 'https://git.example/sdoc/app/actions/runs/7/jobs/0')]);

    expect(app(CommitChecks::class)->refresh($this->repo, 'aaa'))->toBe(Checks::FAILED);

    $checks = [['name' => 'tests / pest (push)', 'state' => 'failed', 'url' => 'https://git.example/sdoc/app/actions/runs/7/jobs/0']];
    expect($commit->refresh()->checks_state)->toBe(Checks::FAILED)
        ->and($commit->checks)->toEqual($checks)
        ->and($commit->checks_checked_at)->not->toBeNull()
        ->and($pr->refresh()->checks_state)->toBe(Checks::FAILED)
        ->and($pr->checks)->toEqual($checks)
        ->and($other->refresh()->checks_state)->toBeNull()
        ->and($otherPr->refresh()->checks_state)->toBeNull()
        ->and($elsewhere->refresh()->checks_state)->toBeNull();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'limit=50') && $r->hasHeader('Authorization', 'token token-sdoc-never-shown'));
});

// git:commit-checks

it('asks about recent commits whose checks are unknown or still running, newest first', function () {
    $unknown = checksCommit($this->repo, 'new', now()->subHour());
    $running = checksCommit($this->repo, 'run', now()->subHours(2));
    $running->forceFill(['checks_state' => Checks::RUNNING, 'checks_checked_at' => now()->subMinutes(5)])->save();
    $justAsked = checksCommit($this->repo, 'asked', now()->subHours(3));
    $justAsked->forceFill(['checks_state' => Checks::RUNNING, 'checks_checked_at' => now()->subSeconds(30)])->save();
    $finished = checksCommit($this->repo, 'done', now()->subHours(4));
    $finished->forceFill(['checks_state' => Checks::PASSED, 'checks_checked_at' => now()->subHour()])->save();
    $untested = checksCommit($this->repo, 'none', now()->subHour());
    $untested->forceFill(['checks_state' => Checks::NONE, 'checks_checked_at' => now()->subMinutes(30)])->save();
    $old = checksCommit($this->repo, 'old', now()->subDays(3));
    $gone = checksCommit($this->repo, 'gone', now()->subHour());
    $gone->forceFill(['unreachable_at' => now()])->save();
    Http::fake([
        'git.example/api/v1/repos/sdoc/app/commits/new/status*' => Http::response(checksCombined('new', [checksStatus('tests (push)', 'success')])),
        'git.example/api/v1/repos/sdoc/app/commits/run/status*' => Http::response(checksCombined('run', [checksStatus('tests (push)', 'failure')])),
    ]);

    $this->artisan('git:commit-checks')->expectsOutput('Read the checks of 2 commits.')->assertSuccessful();

    expect($unknown->refresh()->checks_state)->toBe(Checks::PASSED)
        ->and($running->refresh()->checks_state)->toBe(Checks::FAILED)
        ->and($justAsked->refresh()->checks_state)->toBe(Checks::RUNNING)
        ->and($untested->refresh()->checks_state)->toBe(Checks::NONE)
        ->and($old->refresh()->checks_state)->toBeNull()
        ->and($gone->refresh()->checks_state)->toBeNull()
        ->and(Http::recorded()->map(fn (array $pair) => (string) parse_url($pair[0]->url(), PHP_URL_PATH))->all())->toBe([
            '/api/v1/repos/sdoc/app/commits/new/status',
            '/api/v1/repos/sdoc/app/commits/run/status',
        ]);
});

it('asks again about a commit with no checks while it is new, in case its run had not started', function () {
    $commit = checksCommit($this->repo, 'aaa', now()->subMinutes(3));
    $commit->forceFill(['checks_state' => Checks::NONE, 'checks_checked_at' => now()->subMinutes(2)])->save();
    checksFake('aaa', [checksStatus('tests (push)', 'pending')]);

    $this->artisan('git:commit-checks')->expectsOutput('Read the checks of 1 commits.')->assertSuccessful();

    expect($commit->refresh()->checks_state)->toBe(Checks::RUNNING);
});

it('stops a connection that answers 401 after one call, and asks the other connection', function () {
    $other = GitConnection::query()->create([
        'organization_id' => Organization::factory()->create()->id,
        'base_url' => 'https://git.other',
        'owner' => 'mof',
        'token' => 'token-mof',
    ]);
    $otherRepo = GitRepository::query()->create([
        'git_connection_id' => $other->id, 'project_id' => Project::factory()->create()->id, 'forge_id' => 9,
        'owner' => 'mof', 'name' => 'api', 'full_name' => 'mof/api', 'html_url' => 'https://git.other/mof/api',
        'clone_url' => 'https://git.other/mof/api.git', 'ssh_url' => 'git@git.other:mof/api.git', 'default_branch' => 'main',
    ]);
    $a = checksCommit($this->repo, 'u1', now()->subMinutes(10));
    $b = checksCommit($this->repo, 'u2', now()->subMinutes(20));
    $c = checksCommit($otherRepo, 'o1', now()->subMinutes(30));
    Http::fake([
        'git.example/*' => Http::response(['message' => 'bad token'], 401),
        'git.other/api/v1/repos/mof/api/commits/o1/status*' => Http::response(checksCombined('o1', [])),
    ]);

    $this->artisan('git:commit-checks')->expectsOutput('Read the checks of 1 commits.')->assertSuccessful();

    Http::assertSentCount(2);
    expect($a->refresh()->checks_checked_at)->toBeNull()
        ->and($b->refresh()->checks_checked_at)->toBeNull()
        ->and($c->refresh()->checks_state)->toBe(Checks::NONE);
});

it('skips a commit the forge fails with a 500 until next time, and asks the next', function () {
    $bad = checksCommit($this->repo, 'bad', now()->subMinutes(10));
    $good = checksCommit($this->repo, 'good', now()->subMinutes(20));
    Http::fake([
        'git.example/api/v1/repos/sdoc/app/commits/bad/status*' => Http::response('boom', 500),
        'git.example/api/v1/repos/sdoc/app/commits/good/status*' => Http::response(checksCombined('good', [checksStatus('tests (push)', 'success')])),
    ]);

    $this->artisan('git:commit-checks')->expectsOutput('Read the checks of 1 commits.')->assertSuccessful();

    expect($bad->refresh()->checks_state)->toBeNull()
        ->and($bad->checks_checked_at)->not->toBeNull()
        ->and($good->refresh()->checks_state)->toBe(Checks::PASSED);
});

it('stops an unreachable connection and logs the cause without the token', function () {
    Log::spy();
    checksCommit($this->repo, 'x1', now()->subMinutes(10));
    checksCommit($this->repo, 'x2', now()->subMinutes(20));
    $calls = 0;
    Http::fake(['git.example/*' => function () use (&$calls) {
        $calls++;

        throw new ConnectionException('cURL error 7: refused token-sdoc-never-shown');
    }]);

    $this->artisan('git:commit-checks')->assertSuccessful();

    expect($calls)->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $context['status'] === 0
        && str_contains($context['cause'], 'refused')
        && ! str_contains((string) json_encode($context), 'never-shown'))->once();
});

it('asks about at most --limit commits', function () {
    checksCommit($this->repo, 'l1', now()->subMinutes(10));
    checksCommit($this->repo, 'l2', now()->subMinutes(20));
    checksCommit($this->repo, 'l3', now()->subMinutes(30));
    Http::fake(['git.example/*' => Http::response(checksCombined('x', []))]);

    $this->artisan('git:commit-checks', ['--limit' => 2])->expectsOutput('Read the checks of 2 commits.')->assertSuccessful();

    Http::assertSentCount(2);
});

it("runs every two minutes, one at a time, a killed run's lock lapsing after 10 minutes", function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'git:commit-checks'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/2 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(10);
});

// Pull requests

it("gives a pull request its head commit's checks, and drops them when the head moves to a commit not read yet", function () {
    $head = checksCommit($this->repo, 'aaa');
    $head->forceFill(['checks_state' => Checks::PASSED, 'checks' => [['name' => 'tests (pull_request)', 'state' => 'passed', 'url' => null]]])->save();
    $ingest = app(GitIngest::class);
    $pr = fn (string $sha, string $updated) => [
        'number' => 3, 'title' => 'Work', 'body' => null, 'state' => 'open', 'url' => 'https://git.example/sdoc/app/pulls/3',
        'head_branch' => 'work', 'head_sha' => $sha, 'author_username' => 'asha', 'opened_at' => '2026-10-30T04:15:00Z',
        'merged_at' => null, 'closed_at' => null, 'updated_at' => $updated,
    ];

    $opened = $ingest->pullRequest($this->repo, $pr('aaa', '2026-10-30T04:15:00Z'));

    expect($opened->head_sha)->toBe('aaa')
        ->and($opened->checks_state)->toBe(Checks::PASSED)
        ->and($opened->checks)->toEqual([['name' => 'tests (pull_request)', 'state' => 'passed', 'url' => null]]);

    $ingest->pullRequest($this->repo, $pr('aaa', '2026-10-30T05:00:00Z'));
    expect($opened->refresh()->checks_state)->toBe(Checks::PASSED);

    $ingest->pullRequest($this->repo, $pr('bbb', '2026-10-30T06:00:00Z'));
    expect($opened->refresh()->head_sha)->toBe('bbb')
        ->and($opened->checks_state)->toBeNull()
        ->and($opened->checks)->toBeNull();
});

// main

it("reads each repository's main from the commit its branch points at", function () {
    $web = checksRepository($this->project, $this->connection, 502, 'web');
    $docs = checksRepository($this->project, $this->connection, 503, 'docs');
    $head = checksCommit($this->repo, 'aaa');
    $head->forceFill(['checks_state' => Checks::FAILED, 'checks' => [['name' => 'tests (push)', 'state' => 'failed', 'url' => null]]])->save();
    checksCommit($this->repo, 'old')->forceFill(['checks_state' => Checks::PASSED, 'checks' => []])->save();
    GitBranch::query()->create(['git_repository_id' => $this->repo->id, 'name' => 'main', 'head_sha' => 'aaa']);
    GitBranch::query()->create(['git_repository_id' => $this->repo->id, 'name' => 'work', 'head_sha' => 'old']);
    // web's main points at a commit not read yet; docs has no main the tracker knows.
    checksCommit($web, 'www');
    GitBranch::query()->create(['git_repository_id' => $web->id, 'name' => 'main', 'head_sha' => 'www']);

    expect(app(MainChecksQuery::class)->get([$this->repo->id, $web->id, $docs->id]))->toEqual([
        $this->repo->id => ['state' => 'failed', 'checks' => [['name' => 'tests (push)', 'state' => 'failed', 'url' => null]]],
    ])->and(app(MainChecksQuery::class)->get([]))->toBe([]);
});

it("follows a repository's default branch, whatever it is called", function () {
    $this->repo->update(['default_branch' => 'master']);
    checksCommit($this->repo, 'mmm')->forceFill(['checks_state' => Checks::PASSED, 'checks' => []])->save();
    checksCommit($this->repo, 'nnn')->forceFill(['checks_state' => Checks::FAILED, 'checks' => []])->save();
    GitBranch::query()->create(['git_repository_id' => $this->repo->id, 'name' => 'master', 'head_sha' => 'mmm']);
    GitBranch::query()->create(['git_repository_id' => $this->repo->id, 'name' => 'main', 'head_sha' => 'nnn']);

    expect(app(MainChecksQuery::class)->get([$this->repo->id]))->toEqual([$this->repo->id => ['state' => 'passed', 'checks' => []]]);
});
