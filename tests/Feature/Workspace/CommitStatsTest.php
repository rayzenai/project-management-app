<?php

use App\Models\GitCommit;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Git\GitIngest;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * `git:commit-stats` against a faked Forgejo 15: GET git/commits/{sha} (parents,
 * stats) and git/commits/{sha}.diff. Stray requests fail the test.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->organization = Organization::factory()->create();
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'key' => 'TP']);
    $this->connection = statsConnection($this->organization, 'sdoc', 'git.example');
    $this->repo = statsRepo($this->project, $this->connection);
});

function statsConnection(Organization $organization, string $owner, string $host): GitConnection
{
    return GitConnection::query()->create([
        'organization_id' => $organization->id,
        'base_url' => "https://{$host}",
        'owner' => $owner,
        'token' => "token-{$owner}-never-shown",
        'webhook_id' => 99,
        'webhook_secret' => 'secret-never-shown',
    ]);
}

function statsRepo(Project $project, GitConnection $connection, int $forgeId = 501, string $name = 'app'): GitRepository
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
    ]);
}

function statsCommit(GitRepository $repo, string $sha, string $at = '2026-10-20 10:00:00', ?string $author = 'asha'): GitCommit
{
    return $repo->commits()->create([
        'sha' => $sha,
        'message' => 'Work',
        'author_name' => 'Asha',
        'author_username' => $author,
        'url' => "https://git.example/sdoc/app/commit/{$sha}",
        'committed_at' => $at,
    ]);
}

const STATS_DIFF = <<<'DIFF'
diff --git a/app/a.php b/app/a.php
index 1..2 100644
--- a/app/a.php
+++ b/app/a.php
@@ -1,2 +1,3 @@
-old();
+// a note
+new();
+
DIFF;

it('stores the forge totals and the classified counts of a normal commit', function () {
    $commit = statsCommit($this->repo, 'aaa');
    Http::fake([
        'git.example/api/v1/repos/sdoc/app/git/commits/aaa.diff' => Http::response(STATS_DIFF."\n"),
        'git.example/api/v1/repos/sdoc/app/git/commits/aaa?*' => Http::response([
            'sha' => 'aaa', 'parents' => [['sha' => 'p1']], 'stats' => ['total' => 4, 'additions' => 3, 'deletions' => 1],
        ]),
    ]);

    $this->artisan('git:commit-stats')->expectsOutput('Counted 1 commits.')->assertSuccessful();

    $commit->refresh();
    expect($commit->stats_status)->toBe('ok')
        ->and($commit->is_merge)->toBeFalse()
        ->and($commit->additions)->toBe(3)
        ->and($commit->deletions)->toBe(1)
        ->and($commit->files_changed)->toBe(1)
        ->and($commit->code_added)->toBe(1)
        ->and($commit->code_removed)->toBe(1)
        ->and($commit->comment_added)->toBe(1)
        ->and($commit->blank_added)->toBe(1)
        ->and($commit->stats_computed_at)->not->toBeNull();
});

it('marks a merge and never asks for its diff', function () {
    $commit = statsCommit($this->repo, 'mmm');
    Http::fake([
        'git.example/api/v1/repos/sdoc/app/git/commits/mmm?*' => Http::response([
            'sha' => 'mmm', 'parents' => [['sha' => 'p1'], ['sha' => 'p2']], 'stats' => ['total' => 9, 'additions' => 9, 'deletions' => 0],
        ]),
    ]);

    $this->artisan('git:commit-stats')->assertSuccessful();

    $commit->refresh();
    expect($commit->stats_status)->toBe('merge')
        ->and($commit->is_merge)->toBeTrue()
        ->and($commit->code_added)->toBe(0)
        ->and($commit->stats_computed_at)->not->toBeNull();
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '.diff'));
});

it('keeps only the totals of a diff over the cap', function () {
    $commit = statsCommit($this->repo, 'big');
    Http::fake([
        'git.example/api/v1/repos/sdoc/app/git/commits/big.diff' => Http::response('x', 200, ['Content-Length' => '5242881']),
        'git.example/api/v1/repos/sdoc/app/git/commits/big?*' => Http::response([
            'sha' => 'big', 'parents' => [['sha' => 'p1']], 'stats' => ['total' => 70, 'additions' => 50, 'deletions' => 20],
        ]),
    ]);

    $this->artisan('git:commit-stats')->assertSuccessful();

    $commit->refresh();
    expect($commit->stats_status)->toBe('too_large')
        ->and($commit->additions)->toBe(50)
        ->and($commit->deletions)->toBe(20)
        ->and($commit->code_added)->toBe(0)
        ->and($commit->stats_computed_at)->not->toBeNull();
});

it('marks a commit the forge no longer has as missing', function () {
    $commit = statsCommit($this->repo, 'gone');
    Http::fake(['git.example/api/v1/repos/sdoc/app/git/commits/gone?*' => Http::response(['message' => 'not found'], 404)]);

    $this->artisan('git:commit-stats')->assertSuccessful();

    $commit->refresh();
    expect($commit->stats_status)->toBe('missing')->and($commit->stats_computed_at)->not->toBeNull();
});

it('skips commits failing with a 503 and carries on with the other connection', function () {
    Log::spy();
    $other = statsConnection(Organization::factory()->create(), 'other', 'git.other');
    $otherRepo = statsRepo($this->project, $other, 502, 'lib');
    $down1 = statsCommit($this->repo, 'd1', '2026-10-01 10:00:00');
    $down2 = statsCommit($this->repo, 'd2', '2026-10-02 10:00:00');
    $fine = statsCommit($otherRepo, 'f1', '2026-10-03 10:00:00');
    Http::fake([
        'git.example/*' => Http::response('down', 503),
        'git.other/api/v1/repos/other/lib/git/commits/f1.diff' => Http::response(STATS_DIFF."\n"),
        'git.other/api/v1/repos/other/lib/git/commits/f1?*' => Http::response([
            'sha' => 'f1', 'parents' => [['sha' => 'p1']], 'stats' => ['total' => 4, 'additions' => 3, 'deletions' => 1],
        ]),
    ]);

    $this->artisan('git:commit-stats')->expectsOutput('Counted 1 commits.')->assertSuccessful();

    expect($down1->refresh()->stats_computed_at)->toBeNull()
        ->and($down2->refresh()->stats_computed_at)->toBeNull()
        ->and($fine->refresh()->stats_status)->toBe('ok');
    Http::assertSentCount(4);
});

it('honours the limit and says what is left', function () {
    $first = statsCommit($this->repo, 'c1', '2026-10-01 10:00:00');
    $second = statsCommit($this->repo, 'c2', '2026-10-02 10:00:00');
    $third = statsCommit($this->repo, 'c3', '2026-10-03 10:00:00');
    Http::fake([
        'git.example/api/v1/repos/sdoc/app/git/commits/*.diff' => fn () => Http::response(STATS_DIFF."\n"),
        'git.example/api/v1/repos/sdoc/app/git/commits/*' => Http::response([
            'parents' => [['sha' => 'p1']], 'stats' => ['total' => 4, 'additions' => 3, 'deletions' => 1],
        ]),
    ]);

    $this->artisan('git:commit-stats', ['--limit' => 2])->expectsOutput('Counted 2 commits. 1 left for the next run.')->assertSuccessful();

    expect($first->refresh()->stats_computed_at)->not->toBeNull()
        ->and($second->refresh()->stats_computed_at)->not->toBeNull()
        ->and($third->refresh()->stats_computed_at)->toBeNull();
});

it('sets user_id on ingest from the forge username, ignoring case', function () {
    $user = User::factory()->create(['git_username' => 'asha']);

    $commit = app(GitIngest::class)->commit($this->repo, [
        'sha' => 'ing', 'message' => 'Work', 'author_name' => 'Asha', 'author_username' => 'Asha',
        'url' => 'https://git.example/x', 'committed_at' => '2026-10-20T10:00:00Z',
    ]);
    $stranger = app(GitIngest::class)->commit($this->repo, [
        'sha' => 'str', 'message' => 'Work', 'author_name' => 'Zed', 'author_username' => 'zed',
        'url' => 'https://git.example/y', 'committed_at' => '2026-10-20T10:00:00Z',
    ]);

    expect($commit->user_id)->toBe($user->id)->and($stranger->user_id)->toBeNull();
});

it('fills user_id on older commits with one update per user', function () {
    $asha = User::factory()->create(['git_username' => 'asha']);
    $ravi = User::factory()->create(['git_username' => 'ravi']);
    $a1 = statsCommit($this->repo, 'a1', author: 'ASHA');
    $a2 = statsCommit($this->repo, 'a2', author: 'asha');
    $r1 = statsCommit($this->repo, 'r1', author: 'ravi');
    $nobody = statsCommit($this->repo, 'n1', author: 'nobody');
    $none = statsCommit($this->repo, 'n2', author: null);
    foreach ([$a1, $a2, $r1, $nobody, $none] as $c) {
        $c->forceFill(['stats_computed_at' => now()])->save();
    }

    $updates = 0;
    DB::listen(function ($q) use (&$updates) {
        if (str_starts_with(strtolower($q->sql), 'update "git_commits"')) {
            $updates++;
        }
    });

    $this->artisan('git:commit-stats')->assertSuccessful();

    expect($a1->refresh()->user_id)->toBe($asha->id)
        ->and($a2->refresh()->user_id)->toBe($asha->id)
        ->and($r1->refresh()->user_id)->toBe($ravi->id)
        ->and($nobody->refresh()->user_id)->toBeNull()
        ->and($none->refresh()->user_id)->toBeNull()
        ->and($updates)->toBe(2);
});

it('stops a connection that answers 401 after one call', function () {
    $a = statsCommit($this->repo, 'u1', '2026-10-01 10:00:00');
    $b = statsCommit($this->repo, 'u2', '2026-10-02 10:00:00');
    Http::fake(['git.example/*' => Http::response(['message' => 'bad token'], 401)]);

    $this->artisan('git:commit-stats')->assertSuccessful();

    Http::assertSentCount(1);
    expect($a->refresh()->stats_computed_at)->toBeNull()->and($b->refresh()->stats_computed_at)->toBeNull();
});

it('skips a commit the forge fails with a 500 and counts the next', function () {
    $bad = statsCommit($this->repo, 'bad', '2026-10-01 10:00:00');
    $good = statsCommit($this->repo, 'good', '2026-10-02 10:00:00');
    Http::fake([
        'git.example/api/v1/repos/sdoc/app/git/commits/bad?*' => Http::response('boom', 500),
        'git.example/api/v1/repos/sdoc/app/git/commits/good.diff' => fn () => Http::response(STATS_DIFF."\n"),
        'git.example/api/v1/repos/sdoc/app/git/commits/good?*' => Http::response([
            'parents' => [['sha' => 'p1']], 'stats' => ['total' => 4, 'additions' => 3, 'deletions' => 1],
        ]),
    ]);

    $this->artisan('git:commit-stats')->expectsOutput('Counted 1 commits.')->assertSuccessful();

    expect($bad->refresh()->stats_computed_at)->toBeNull()->and($good->refresh()->stats_status)->toBe('ok');
});

it('stops a connection after five 500s in a row', function () {
    foreach (range(1, 8) as $i) {
        statsCommit($this->repo, "e{$i}", "2026-10-0{$i} 10:00:00");
    }
    Http::fake(['git.example/*' => Http::response('boom', 500)]);

    $this->artisan('git:commit-stats')->expectsOutput('Counted 0 commits.')->assertSuccessful();

    Http::assertSentCount(5);
    expect(GitCommit::query()->whereNotNull('stats_computed_at')->count())->toBe(0);
});

it('stops an unreachable connection and logs the cause without the token', function () {
    Log::spy();
    statsCommit($this->repo, 'x1', '2026-10-01 10:00:00');
    statsCommit($this->repo, 'x2', '2026-10-02 10:00:00');
    $calls = 0;
    Http::fake(['git.example/*' => function () use (&$calls) {
        $calls++;

        throw new ConnectionException('cURL error 7: refused token-sdoc-never-shown');
    }]);

    $this->artisan('git:commit-stats')->assertSuccessful();

    expect($calls)->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) {
        return $context['status'] === 0
            && str_contains($context['cause'], 'refused')
            && ! str_contains(json_encode($context), 'never-shown');
    })->once();
});

it('counts failed attempts toward the limit', function () {
    foreach (['l1', 'l2', 'l3'] as $i => $sha) {
        statsCommit($this->repo, $sha, '2026-10-0'.($i + 1).' 10:00:00');
    }
    Http::fake(['git.example/*' => Http::response(['message' => 'bad'], 422)]);

    $this->artisan('git:commit-stats', ['--limit' => 2])->expectsOutput('Counted 0 commits. 3 left for the next run.')->assertSuccessful();

    Http::assertSentCount(2);
});

it("runs every five minutes, one at a time, a killed run's lock lapsing after 30 minutes", function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'git:commit-stats'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(30);
});
