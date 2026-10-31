<?php

use App\Jobs\ProcessGitDelivery;
use App\Models\GitBranch;
use App\Models\GitCommit;
use App\Models\GitConnection;
use App\Models\GitPullRequest;
use App\Models\GitRepository;
use App\Models\GitWebhookDelivery;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Task;
use App\Models\TaskGitLink;
use App\Services\Git\CommitChecks;
use App\Services\Git\GitIngest;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Fixtures follow Forgejo 15's webhook structs (modules/structs/hook.go,
 * repo.go, pull.go, user.go) as services/webhook/notifier.go fills them, and
 * the headers of services/webhook/shared/payloader.go AddDefaultHeaders.
 */

beforeEach(function () {
    $this->organization = Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
    $this->connection = GitConnection::query()->create([
        'organization_id' => $this->organization->id,
        'base_url' => 'https://git.example',
        'owner' => 'sdoc',
        'token' => 'bot-token',
        'webhook_id' => 99,
        'webhook_secret' => 'hook-secret-0123456789abcdef0123456789ab',
    ]);
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Tax Portal', 'key' => 'TP']);
    $this->task = Task::factory()->for($this->project)->create();
    $this->repo = GitRepository::query()->create([
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
});

/** @return array<string, mixed> api.User */
function hookUser(string $login): array
{
    return [
        'id' => crc32($login),
        'login' => $login,
        'login_name' => '',
        'source_id' => 0,
        'full_name' => ucfirst($login),
        'email' => "{$login}@noreply.git.example",
        'avatar_url' => "https://git.example/avatars/{$login}",
        'html_url' => "https://git.example/{$login}",
        'language' => '',
        'is_admin' => false,
        'last_login' => '0001-01-01T00:00:00Z',
        'created' => '2026-09-01T10:00:00+05:45',
        'restricted' => false,
        'active' => false,
        'prohibit_login' => false,
        'location' => '',
        'pronouns' => '',
        'website' => '',
        'description' => '',
        'visibility' => 'private',
        'followers_count' => 0,
        'following_count' => 0,
        'starred_repos_count' => 0,
        'username' => $login,
    ];
}

/** @return array<string, mixed> api.Repository (the fields a webhook carries; the rest are left out) */
function hookRepository(int $id = 501, string $owner = 'sdoc', string $name = 'app'): array
{
    return [
        'id' => $id,
        'owner' => hookUser($owner),
        'name' => $name,
        'full_name' => "{$owner}/{$name}",
        'description' => '',
        'empty' => false,
        'private' => true,
        'fork' => false,
        'template' => false,
        'parent' => null,
        'mirror' => false,
        'size' => 120,
        'language' => '',
        'languages_url' => "https://git.example/api/v1/repos/{$owner}/{$name}/languages",
        'html_url' => "https://git.example/{$owner}/{$name}",
        'url' => "https://git.example/api/v1/repos/{$owner}/{$name}",
        'link' => '',
        'ssh_url' => "git@git.example:{$owner}/{$name}.git",
        'clone_url' => "https://git.example/{$owner}/{$name}.git",
        'original_url' => '',
        'website' => '',
        'stars_count' => 0,
        'forks_count' => 0,
        'watchers_count' => 1,
        'open_issues_count' => 0,
        'open_pr_counter' => 0,
        'release_counter' => 0,
        'default_branch' => 'main',
        'archived' => false,
        'created_at' => '2026-09-01T10:00:00+05:45',
        'updated_at' => '2026-10-30T10:00:00+05:45',
        'archived_at' => '1970-01-01T05:45:00+05:45',
        'permissions' => ['admin' => true, 'push' => true, 'pull' => true],
        'has_issues' => true,
        'has_pull_requests' => true,
        'default_branch_delete_after_merge' => false,
        'object_format_name' => 'sha1',
        'mirror_updated' => '0001-01-01T00:00:00Z',
        'repo_transfer' => null,
        'topics' => null,
    ];
}

/** @return array<string, mixed> api.PayloadCommit */
function hookCommit(string $message, string $sha = 'a1b2c3d4e5f60718293a4b5c6d7e8f9001122334'): array
{
    $author = ['name' => 'Bikash Dev', 'email' => 'bikash@example.com', 'username' => 'bikash'];

    return [
        'id' => $sha,
        'message' => $message."\n",
        'url' => "https://git.example/sdoc/app/commit/{$sha}",
        'author' => $author,
        'committer' => $author,
        'verification' => null,
        'timestamp' => '2026-10-30T10:00:00+05:45',
        'added' => [],
        'removed' => [],
        'modified' => ['app/Login.php'],
    ];
}

/**
 * @param  list<array<string, mixed>>  $commits
 * @param  array<string, mixed>|null  $repository
 * @return array<string, mixed> api.PushPayload
 */
function hookPush(array $commits, string $ref = 'refs/heads/main', ?array $repository = null): array
{
    $after = $commits === [] ? 'f1f2f3f4f5f60718293a4b5c6d7e8f9001122334' : $commits[0]['id'];

    return [
        'ref' => $ref,
        'before' => '0000000000000000000000000000000000000000',
        'after' => $after,
        'compare_url' => 'https://git.example/sdoc/app/compare/main...'.$after,
        'commits' => $commits,
        'total_commits' => count($commits),
        'head_commit' => $commits[0] ?? null,
        'repository' => $repository ?? hookRepository(),
        'pusher' => hookUser('bikash'),
        'sender' => hookUser('bikash'),
    ];
}

/**
 * A pull request as Forgejo sends it. `head.label` is always the head
 * branch; `head.ref` is too while the branch exists, and
 * `refs/pull/{n}/head` once it is deleted (services/convert/pull.go).
 *
 * @param  array<string, mixed>  $overrides  keys of pull_request
 * @return array<string, mixed> api.PullRequestPayload
 */
function hookPullRequest(string $action, array $overrides = [], string $branch = 'work'): array
{
    $repository = hookRepository();

    $pullRequest = array_merge([
        'id' => 77,
        'url' => 'https://git.example/sdoc/app/pulls/3',
        'number' => 3,
        'user' => hookUser('bikash'),
        'title' => 'TP-1 login',
        'body' => '',
        'labels' => [],
        'milestone' => null,
        'assignee' => null,
        'assignees' => null,
        'requested_reviewers' => null,
        'requested_reviewers_teams' => null,
        'state' => 'open',
        'draft' => false,
        'is_locked' => false,
        'comments' => 0,
        'review_comments' => 0,
        'additions' => 12,
        'deletions' => 3,
        'changed_files' => 2,
        'html_url' => 'https://git.example/sdoc/app/pulls/3',
        'diff_url' => 'https://git.example/sdoc/app/pulls/3.diff',
        'patch_url' => 'https://git.example/sdoc/app/pulls/3.patch',
        'mergeable' => true,
        'merged' => false,
        'merged_at' => null,
        'merge_commit_sha' => null,
        'merged_by' => null,
        'allow_maintainer_edit' => false,
        'base' => ['label' => 'main', 'ref' => 'main', 'sha' => 'b1b2c3d4e5f60718293a4b5c6d7e8f9001122334', 'repo_id' => 501, 'repo' => $repository],
        'head' => ['label' => $branch, 'ref' => $branch, 'sha' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9001122334', 'repo_id' => 501, 'repo' => $repository],
        'merge_base' => 'b1b2c3d4e5f60718293a4b5c6d7e8f9001122334',
        'due_date' => null,
        'created_at' => '2026-10-30T10:00:00+05:45',
        'updated_at' => '2026-10-30T10:00:00+05:45',
        'closed_at' => null,
        'pin_order' => 0,
        'flow' => 0,
    ], $overrides);

    return [
        'action' => $action,
        'number' => $pullRequest['number'],
        'pull_request' => $pullRequest,
        'requested_reviewer' => null,
        'repository' => $repository,
        'sender' => hookUser('bikash'),
        'commit_id' => '',
        'review' => null,
    ];
}

/**
 * Posts a delivery signed with the connection's secret, with every header
 * Forgejo sends. A header set to null is left out.
 *
 * Under FPM every request has its own app, so a request's after-response
 * work runs once. The test app is shared, and Application::terminate()
 * keeps its callbacks: put them back as they were before the request, or
 * the next request would run this one's work again.
 *
 * @param  array<string, mixed>  $payload
 * @param  array<string, ?string>  $headers
 */
function hookSend(GitConnection $connection, string $event, array $payload, array $headers = [], ?string $body = null): TestResponse
{
    [$server, $body] = hookServer($connection, $event, $payload, $headers, $body);
    $callbacks = (fn () => $this->terminatingCallbacks)->call(app());

    $response = test()->call('POST', route('git.webhook', $connection), [], [], [], $server, $body);

    (fn () => $this->terminatingCallbacks = $callbacks)->call(app());

    return $response;
}

/**
 * The server variables and raw body of a signed delivery.
 *
 * @param  array<string, mixed>  $payload
 * @param  array<string, ?string>  $headers
 * @return array{0: array<string, string>, 1: string}
 */
function hookServer(GitConnection $connection, string $event, array $payload, array $headers = [], ?string $body = null): array
{
    $body ??= (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $delivery = (string) Str::uuid();
    $signature = hash_hmac('sha256', $body, (string) $connection->webhook_secret);

    $headers = array_merge([
        'X-Forgejo-Delivery' => $delivery,
        'X-Forgejo-Event' => $event,
        'X-Forgejo-Event-Type' => $event,
        'X-Forgejo-Signature' => $signature,
        'X-Gitea-Delivery' => $delivery,
        'X-Gitea-Event' => $event,
        'X-Gitea-Event-Type' => $event,
        'X-Gitea-Signature' => $signature,
        'X-Hub-Signature-256' => 'sha256='.$signature,
    ], $headers);

    $server = ['CONTENT_TYPE' => 'application/json'];
    foreach ($headers as $name => $value) {
        if ($value !== null) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
    }

    return [$server, $body];
}

/** @return list<string> */
function hookEntries(Task $task): array
{
    return ProjectActivity::query()->where('task_id', $task->id)->where('subject_type', 'git-pull-request')->orderBy('id')->pluck('description')->all();
}

// Intake

it('answers 404 for a connection that does not exist', function () {
    $gone = new GitConnection(['webhook_secret' => 'hook-secret-0123456789abcdef0123456789ab']);
    $gone->id = 999999;

    hookSend($gone, 'push', hookPush([hookCommit('TP-1 fix')]))->assertNotFound();

    expect(GitWebhookDelivery::query()->count())->toBe(0);
});

it('throttles webhooks on their own counter, so a busy forge never locks out forge sign-in', function () {
    $gone = new GitConnection(['webhook_secret' => 'hook-secret-0123456789abcdef0123456789ab']);
    $gone->id = 999999;

    foreach (range(1, 61) as $ignored) {
        hookSend($gone, 'push', hookPush([]))->assertNotFound();
    }

    // The forge's token call, from the same address.
    expect($this->post('/oauth/token')->getStatusCode())->not->toBe(429);
});

it('answers 413 for a body over 2 MB, and stores nothing', function () {
    $payload = hookPush([hookCommit('TP-1 '.str_repeat('a', 2 * 1024 * 1024))]);

    hookSend($this->connection, 'push', $payload)->assertStatus(413);

    expect(GitWebhookDelivery::query()->count())->toBe(0)
        ->and(GitCommit::query()->count())->toBe(0);
});

it('answers 403 and changes nothing for a missing or wrong signature', function (array $headers) {
    Queue::fake();

    $response = hookSend($this->connection, 'push', hookPush([hookCommit('TP-1 fix')]), $headers)->assertForbidden();

    expect(GitWebhookDelivery::query()->count())->toBe(0)
        ->and(GitCommit::query()->count())->toBe(0)
        ->and($response->getContent())->not->toContain('hook-secret');
    Queue::assertNothingPushed();
})->with([
    'no signature' => [['X-Forgejo-Signature' => null, 'X-Gitea-Signature' => null]],
    'wrong signature' => [['X-Forgejo-Signature' => str_repeat('0', 64), 'X-Gitea-Signature' => str_repeat('0', 64)]],
    'another secret' => [['X-Forgejo-Signature' => hash_hmac('sha256', 'x', 'y'), 'X-Gitea-Signature' => null]],
    'a wrong Forgejo signature is not rescued by a right Gitea one' => [['X-Forgejo-Signature' => str_repeat('0', 64)]],
    'empty' => [['X-Forgejo-Signature' => '', 'X-Gitea-Signature' => '']],
]);

it('marks the hook for replacing only for a signature that is there and wrong', function () {
    $key = GitWebhookDelivery::badSignatureKey($this->connection->id);

    hookSend($this->connection, 'push', hookPush([]), ['X-Forgejo-Signature' => null, 'X-Gitea-Signature' => null])->assertForbidden();
    expect(Cache::has($key))->toBeFalse();

    hookSend($this->connection, 'push', hookPush([]), ['X-Forgejo-Signature' => str_repeat('0', 64)])->assertForbidden();
    expect(Cache::has($key))->toBeTrue();
});

it('answers 403 when the connection has no webhook secret', function () {
    $this->connection->forceFill(['webhook_secret' => null])->save();

    // hookSend signs with an empty key here.
    hookSend($this->connection, 'push', hookPush([hookCommit('TP-1 fix')]))->assertForbidden();

    expect(GitWebhookDelivery::query()->count())->toBe(0);
});

it('falls back to the X-Gitea-Signature header when X-Forgejo-Signature is absent', function () {
    hookSend($this->connection, 'push', hookPush([hookCommit('TP-1 fix')]), ['X-Forgejo-Signature' => null])
        ->assertStatus(202);

    expect(GitCommit::query()->count())->toBe(1);
});

it('answers 400 for a signed request without a delivery id', function (array $headers) {
    hookSend($this->connection, 'push', hookPush([hookCommit('TP-1 fix')]), $headers)->assertStatus(400);

    expect(GitWebhookDelivery::query()->count())->toBe(0)
        ->and(GitCommit::query()->count())->toBe(0);
})->with([
    'missing' => [['X-Forgejo-Delivery' => null]],
    'not a uuid' => [['X-Forgejo-Delivery' => 'abc']],
]);

it('answers 400 for a signed body that is not a JSON object', function () {
    hookSend($this->connection, 'push', [], body: 'payload=%7B%7D')->assertStatus(400);

    expect(GitWebhookDelivery::query()->count())->toBe(0);
});

it('stores a signed delivery, queues it on a worker queue, and answers 202 without echoing the signature', function () {
    config(['queue.default' => 'database']);
    Queue::fake();
    $delivery = (string) Str::uuid();
    $payload = hookPush([hookCommit('TP-1 fix')]);
    $signature = hash_hmac('sha256', (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), (string) $this->connection->webhook_secret);

    $response = hookSend($this->connection, 'push', $payload, ['X-Forgejo-Delivery' => $delivery])
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Received.']);

    expect($response->getContent())->not->toContain($signature)->not->toContain('hook-secret');
    $stored = GitWebhookDelivery::query()->sole();
    expect($stored->delivery)->toBe($delivery)
        ->and($stored->git_connection_id)->toBe($this->connection->id)
        ->and($stored->event)->toBe('push')
        ->and($stored->payload)->toEqual($payload)
        ->and($stored->received_at)->not->toBeNull()
        ->and($stored->processed_at)->toBeNull()
        ->and($stored->error)->toBeNull();
    Queue::assertPushed(ProcessGitDelivery::class, fn (ProcessGitDelivery $job) => $job->delivery->is($stored) && $job->connection === null);
});

it('on the sync queue, answers 202 first and does the work once the response is sent', function () {
    [$server, $body] = hookServer($this->connection, 'push', hookPush([hookCommit('TP-1 fix')]));
    $request = Request::create(route('git.webhook', $this->connection), 'POST', [], [], [], $server, $body);
    $kernel = app(HttpKernel::class);

    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(202)
        ->and(GitCommit::query()->count())->toBe(0)
        ->and(GitWebhookDelivery::query()->sole()->processed_at)->toBeNull();

    $kernel->terminate($request, $response);

    expect($this->task->commits()->count())->toBe(1)
        ->and(GitWebhookDelivery::query()->sole()->processed_at)->not->toBeNull();
});

it('answers 200 to a redelivery of a processed delivery and does nothing', function () {
    $delivery = (string) Str::uuid();
    $payload = hookPush([hookCommit('TP-1 fix')]);

    hookSend($this->connection, 'push', $payload, ['X-Forgejo-Delivery' => $delivery])->assertStatus(202);
    GitCommit::query()->delete();

    Queue::fake();
    hookSend($this->connection, 'push', $payload, ['X-Forgejo-Delivery' => $delivery])
        ->assertOk()
        ->assertExactJson(['message' => 'Already received.']);

    expect(GitWebhookDelivery::query()->count())->toBe(1)
        ->and(GitCommit::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('stores an event outside the five as processed and does not queue it', function () {
    Queue::fake();

    hookSend($this->connection, 'pull_request_approved', hookPullRequest('reviewed'))->assertStatus(202);

    $stored = GitWebhookDelivery::query()->sole();
    expect($stored->event)->toBe('pull_request_approved')
        ->and($stored->processed_at)->not->toBeNull();
    Queue::assertNothingPushed();
});

it('still answers 202 when the inline run fails, records the error, and a redelivery processes it', function () {
    Exceptions::fake();
    $delivery = (string) Str::uuid();
    $payload = hookPush([hookCommit('TP-1 fix')]);
    $this->mock(GitIngest::class, fn ($mock) => $mock->shouldReceive('commit')->andThrow(new RuntimeException('database went away')));

    hookSend($this->connection, 'push', $payload, ['X-Forgejo-Delivery' => $delivery])->assertStatus(202);

    $stored = GitWebhookDelivery::query()->sole();
    expect($stored->processed_at)->toBeNull()
        ->and($stored->error)->toContain('database went away')
        ->and(GitCommit::query()->count())->toBe(0);
    Exceptions::assertReported(RuntimeException::class);

    app()->forgetInstance(GitIngest::class);
    hookSend($this->connection, 'push', $payload, ['X-Forgejo-Delivery' => $delivery])
        ->assertOk()
        ->assertExactJson(['message' => 'Already received.']);

    expect($stored->fresh()?->processed_at)->not->toBeNull()
        ->and($stored->fresh()?->error)->toBeNull()
        ->and($this->task->commits()->count())->toBe(1);
});

it('still answers 202 when the worker queue is down, and a redelivery queues it again', function () {
    Exceptions::fake();
    Queue::extend('broken', fn () => new class implements ConnectorInterface
    {
        public function connect(array $config)
        {
            throw new RuntimeException('Connection refused');
        }
    });
    config(['queue.connections.broken' => ['driver' => 'broken'], 'queue.default' => 'broken']);
    $delivery = (string) Str::uuid();
    $payload = hookPush([hookCommit('TP-1 fix')]);

    hookSend($this->connection, 'push', $payload, ['X-Forgejo-Delivery' => $delivery])->assertStatus(202);

    expect(GitWebhookDelivery::query()->sole()->processed_at)->toBeNull();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Connection refused');

    config(['queue.default' => 'sync']);
    hookSend($this->connection, 'push', $payload, ['X-Forgejo-Delivery' => $delivery])->assertOk();

    expect(GitWebhookDelivery::query()->sole()->processed_at)->not->toBeNull()
        ->and($this->task->commits()->count())->toBe(1);
});

it('keeps a failed query\'s bindings out of the error column and the log', function () {
    Exceptions::fake();
    $pdo = new class('SQLSTATE[22001]: String data, right truncated') extends PDOException
    {
        protected $code = '22001';
    };
    $this->mock(GitIngest::class, fn ($mock) => $mock->shouldReceive('commit')->andThrow(
        new QueryException('pgsql', 'insert into "git_commits" ("message") values (?)', ['TP-1 the secret plan'], $pdo),
    ));

    hookSend($this->connection, 'push', hookPush([hookCommit('TP-1 the secret plan')]))->assertStatus(202);

    expect(GitWebhookDelivery::query()->sole()->error)->toBe('Illuminate\Database\QueryException (SQLSTATE 22001)');
    Exceptions::assertNotReported(QueryException::class);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Illuminate\Database\QueryException (SQLSTATE 22001)');
});

it('does nothing when the job runs again for a processed delivery', function () {
    hookSend($this->connection, 'push', hookPush([hookCommit('TP-1 fix')]))->assertStatus(202);
    GitCommit::query()->delete();

    (new ProcessGitDelivery(GitWebhookDelivery::query()->sole()))->handle(app(GitIngest::class), app(CommitChecks::class));

    expect(GitCommit::query()->count())->toBe(0);
});

it('rethrows a failure so the queue retries it, three tries a minute apart', function () {
    $stored = GitWebhookDelivery::query()->create([
        'git_connection_id' => $this->connection->id,
        'delivery' => (string) Str::uuid(),
        'event' => 'push',
        'payload' => hookPush([hookCommit('TP-1 fix')]),
        'received_at' => now(),
    ]);
    $this->mock(GitIngest::class, fn ($mock) => $mock->shouldReceive('commit')->andThrow(new RuntimeException('database went away')));
    $job = new ProcessGitDelivery($stored);

    expect(fn () => $job->handle(app(GitIngest::class), app(CommitChecks::class)))->toThrow(RuntimeException::class, 'database went away')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(60)
        ->and($stored->fresh()?->error)->toContain('database went away');
});

// Processing

it('ingests a signed push: its commits link TP-1, the branch head moves and the push time is kept', function () {
    hookSend($this->connection, 'push', hookPush([
        hookCommit('TP-1 fix the login'),
        hookCommit('Tidy up', 'c1c2c3d4e5f60718293a4b5c6d7e8f9001122334'),
    ]))->assertStatus(202);

    $commit = GitCommit::query()->where('sha', 'a1b2c3d4e5f60718293a4b5c6d7e8f9001122334')->sole();
    expect(GitCommit::query()->count())->toBe(2)
        ->and($commit->message)->toBe("TP-1 fix the login\n")
        ->and($commit->author_name)->toBe('Bikash Dev')
        ->and($commit->author_username)->toBe('bikash')
        ->and($commit->url)->toBe('https://git.example/sdoc/app/commit/a1b2c3d4e5f60718293a4b5c6d7e8f9001122334')
        ->and($this->task->commits()->pluck('git_commits.id')->all())->toBe([$commit->id])
        ->and(GitBranch::query()->where('name', 'main')->value('head_sha'))->toBe('a1b2c3d4e5f60718293a4b5c6d7e8f9001122334')
        ->and($this->repo->fresh()?->last_pushed_at)->not->toBeNull()
        ->and(GitWebhookDelivery::query()->sole()->processed_at)->not->toBeNull();
});

it('stores a push to a repository no project links, and creates nothing', function () {
    hookSend($this->connection, 'push', hookPush([hookCommit('TP-1 fix')], repository: hookRepository(999, 'sdoc', 'other')))
        ->assertStatus(202);

    expect(GitWebhookDelivery::query()->sole()->processed_at)->not->toBeNull()
        ->and(GitCommit::query()->count())->toBe(0)
        ->and(GitBranch::query()->count())->toBe(0)
        ->and(TaskGitLink::query()->count())->toBe(0);
});

it('ignores a repository of another connection with the same forge id', function () {
    $other = Organization::factory()->create(['name' => 'OPMCM', 'slug' => 'opmcm']);
    $otherConnection = GitConnection::query()->create([
        'organization_id' => $other->id,
        'base_url' => 'https://git.other',
        'owner' => 'opmcm',
        'token' => 'bot-token',
        'webhook_secret' => 'other-secret-0123456789abcdef0123456789',
    ]);

    hookSend($otherConnection, 'push', hookPush([hookCommit('TP-1 fix')]))->assertStatus(202);

    expect(GitCommit::query()->count())->toBe(0);
});

it('takes a pull request from opened through edited to merged: one link and the activity rows', function () {
    hookSend($this->connection, 'pull_request', hookPullRequest('opened'))->assertStatus(202);
    hookSend($this->connection, 'pull_request', hookPullRequest('edited', ['body' => 'Now with tests']))->assertStatus(202);
    hookSend($this->connection, 'pull_request', hookPullRequest('closed', [
        'body' => 'Now with tests',
        'state' => 'closed',
        'merged' => true,
        'merged_at' => '2026-10-31T10:00:00+05:45',
        'closed_at' => '2026-10-31T10:00:00+05:45',
        'merge_commit_sha' => 'd1d2c3d4e5f60718293a4b5c6d7e8f9001122334',
        'merged_by' => hookUser('sita'),
    ]))->assertStatus(202);

    $pr = GitPullRequest::query()->sole();
    expect($pr->state)->toBe(GitPullRequest::STATE_MERGED)
        ->and($pr->number)->toBe(3)
        ->and($pr->title)->toBe('TP-1 login')
        ->and($pr->body)->toBe('Now with tests')
        ->and($pr->url)->toBe('https://git.example/sdoc/app/pulls/3')
        ->and($pr->head_branch)->toBe('work')
        ->and($pr->author_username)->toBe('bikash')
        ->and($pr->merged_at?->format('Y-m-d H:i'))->toBe('2026-10-31 10:00')
        ->and(TaskGitLink::query()->pluck('task_id')->all())->toBe([$this->task->id])
        ->and(hookEntries($this->task))->toBe(['Pull request #3 opened: TP-1 login', 'Pull request #3 merged']);
});

it('marks a pull request closed without a merge as closed', function () {
    hookSend($this->connection, 'pull_request', hookPullRequest('closed', ['state' => 'closed', 'closed_at' => '2026-10-31T10:00:00+05:45']))
        ->assertStatus(202);

    expect(GitPullRequest::query()->sole()->state)->toBe(GitPullRequest::STATE_CLOSED);
});

it('keeps the head branch, and the link it makes, after the branch is deleted', function () {
    hookSend($this->connection, 'pull_request', hookPullRequest('opened', ['title' => 'Login'], 'tp-1-login'))->assertStatus(202);

    $closed = hookPullRequest('closed', ['title' => 'Login', 'state' => 'closed', 'merged' => true, 'merged_at' => '2026-10-31T10:00:00+05:45', 'closed_at' => '2026-10-31T10:00:00+05:45'], 'tp-1-login');
    $closed['pull_request']['head']['ref'] = 'refs/pull/3/head';
    hookSend($this->connection, 'pull_request', $closed)->assertStatus(202);

    expect(GitPullRequest::query()->sole()->head_branch)->toBe('tp-1-login')
        ->and(TaskGitLink::query()->pluck('task_id')->all())->toBe([$this->task->id]);
});

it('adds a created branch and removes a deleted one; tags are not branches', function () {
    $create = fn (string $ref, string $type) => [
        'sha' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9001122334',
        'ref' => $ref,
        'ref_type' => $type,
        'repository' => hookRepository(),
        'sender' => hookUser('bikash'),
    ];

    hookSend($this->connection, 'create', $create('refs/heads/tp-1-login', 'branch'))->assertStatus(202);
    hookSend($this->connection, 'create', $create('refs/tags/v1.0.0', 'tag'))->assertStatus(202);

    $branch = GitBranch::query()->sole();
    expect($branch->name)->toBe('tp-1-login')
        ->and($branch->head_sha)->toBe('a1b2c3d4e5f60718293a4b5c6d7e8f9001122334')
        ->and($this->task->branches()->pluck('git_branches.id')->all())->toBe([$branch->id]);

    hookSend($this->connection, 'delete', [
        'ref' => 'refs/heads/tp-1-login',
        'ref_type' => 'branch',
        'pusher_type' => 'user',
        'repository' => hookRepository(),
        'sender' => hookUser('bikash'),
    ])->assertStatus(202);

    expect(GitBranch::query()->count())->toBe(0)
        ->and(TaskGitLink::query()->count())->toBe(0)
        ->and(GitWebhookDelivery::query()->whereNull('processed_at')->count())->toBe(0);
});

it('follows a rename, which Forgejo announces only through later deliveries', function () {
    hookSend($this->connection, 'push', hookPush([hookCommit('TP-1 fix')], repository: hookRepository(501, 'sdoc', 'tax-app')))
        ->assertStatus(202);

    $repo = $this->repo->fresh();
    expect($repo?->forge_id)->toBe(501)
        ->and($repo?->name)->toBe('tax-app')
        ->and($repo?->full_name)->toBe('sdoc/tax-app')
        ->and($repo?->html_url)->toBe('https://git.example/sdoc/tax-app')
        ->and($repo?->clone_url)->toBe('https://git.example/sdoc/tax-app.git')
        ->and($repo?->ssh_url)->toBe('git@git.example:sdoc/tax-app.git')
        ->and($this->task->commits()->count())->toBe(1);
});

it('unlinks a repository deleted on the forge, with its links', function () {
    hookSend($this->connection, 'push', hookPush([hookCommit('TP-1 fix')]))->assertStatus(202);
    Log::spy();

    hookSend($this->connection, 'repository', [
        'action' => 'deleted',
        'repository' => hookRepository(),
        'organization' => hookUser('sdoc'),
        'sender' => hookUser('forge-admin'),
    ])->assertStatus(202);

    expect(GitRepository::query()->count())->toBe(0)
        ->and(GitCommit::query()->count())->toBe(0)
        ->and(TaskGitLink::query()->count())->toBe(0)
        ->and(ProjectActivity::query()->where('project_id', $this->project->id)->whereNull('task_id')->orderBy('id')->pluck('description')->all())
        ->toBe(['Linked repository sdoc/app', 'Unlinked repository sdoc/app']);
    // Its history is gone from the tracker: production logs from warning up.
    Log::shouldHaveReceived('warning')->with('Unlinked a repository deleted on the forge.', ['repository' => 'sdoc/app'])->once();
});

it('logs the unlink of a forge-deleted repository only once the delivery has committed', function () {
    $levelAtLog = null;
    Log::shouldReceive('warning')
        ->withArgs(fn (string $message) => $message === 'Unlinked a repository deleted on the forge.')
        ->once()
        ->andReturnUsing(function () use (&$levelAtLog): void {
            $levelAtLog = DB::transactionLevel();
        });
    Log::shouldIgnoreMissing();
    $before = DB::transactionLevel();

    hookSend($this->connection, 'repository', [
        'action' => 'deleted',
        'repository' => hookRepository(),
        'organization' => hookUser('sdoc'),
        'sender' => hookUser('forge-admin'),
    ])->assertStatus(202);

    // Not inside the job's own transaction, which would be one level deeper.
    expect($levelAtLog)->toBe($before);
});

it('leaves a repository created on the forge alone: nothing links it yet', function () {
    hookSend($this->connection, 'repository', [
        'action' => 'created',
        'repository' => hookRepository(777, 'sdoc', 'new-app'),
        'organization' => hookUser('sdoc'),
        'sender' => hookUser('forge-admin'),
    ])->assertStatus(202);

    expect(GitRepository::query()->pluck('forge_id')->all())->toBe([501])
        ->and(GitWebhookDelivery::query()->sole()->processed_at)->not->toBeNull();
});

/**
 * A finished workflow run, as services/webhook/notifier.go ActionRunNowDone
 * sends it: no top-level repository, the run names it.
 *
 * @return array<string, mixed> api.ActionPayload
 */
function hookActionRun(string $action, string $sha = 'a1b2c3d4e5f60718293a4b5c6d7e8f9001122334', ?array $repository = null): array
{
    $run = [
        'id' => 41,
        'title' => 'TP-1 fix the login',
        'repository' => $repository ?? hookRepository(),
        'workflow_id' => 'tests.yml',
        'index_in_repo' => 7,
        'trigger_user' => hookUser('bikash'),
        'ScheduleID' => 0,
        'prettyref' => 'main',
        'is_ref_deleted' => false,
        'commit_sha' => $sha,
        'is_fork_pull_request' => false,
        'need_approval' => false,
        'approved_by' => 0,
        'event' => 'push',
        'event_payload' => '{}',
        'trigger_event' => 'push',
        'status' => $action,
        'started' => '2026-10-31T10:00:00+05:45',
        'stopped' => '2026-10-31T10:03:00+05:45',
        'created' => '2026-10-31T09:59:00+05:45',
        'updated' => '2026-10-31T10:03:00+05:45',
        'duration' => 180000000000,
        'html_url' => 'https://git.example/sdoc/app/actions/runs/7',
    ];

    return ['action' => $action, 'run' => $run, 'prior_status' => 'running', 'last_run' => null];
}

// Checks
it("reads a finished run's commit checks, for the repository the run names", function (string $event, string $status, string $state) {
    hookSend($this->connection, 'push', hookPush([hookCommit('TP-1 fix the login')]))->assertStatus(202);
    Http::fake(['git.example/api/v1/repos/sdoc/app/commits/a1b2c3d4e5f60718293a4b5c6d7e8f9001122334/status*' => Http::response([
        'state' => $status,
        'sha' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9001122334',
        'total_count' => 1,
        'statuses' => [['id' => 1, 'status' => $status, 'target_url' => 'https://git.example/sdoc/app/actions/runs/7/jobs/0', 'description' => '', 'url' => '', 'context' => 'tests / pest (push)', 'creator' => null]],
    ])]);

    hookSend($this->connection, $event, hookActionRun($status === 'success' ? 'success' : 'failure'))->assertStatus(202);

    $commit = GitCommit::query()->sole();
    expect($commit->checks_state)->toBe($state)
        ->and($commit->checks)->toEqual([['name' => 'tests / pest (push)', 'state' => $state, 'url' => 'https://git.example/sdoc/app/actions/runs/7/jobs/0']])
        ->and(GitWebhookDelivery::query()->where('event', $event)->sole()->processed_at)->not->toBeNull();
})->with([
    'success' => ['action_run_success', 'success', 'passed'],
    'failure' => ['action_run_failure', 'failure', 'failed'],
]);

it('leaves a finished run to git:commit-checks when the forge does not answer', function () {
    hookSend($this->connection, 'push', hookPush([hookCommit('TP-1 fix the login')]))->assertStatus(202);
    Http::fake(['git.example/*' => Http::response('boom', 502)]);

    hookSend($this->connection, 'action_run_success', hookActionRun('success'))->assertStatus(202);

    expect(GitCommit::query()->sole()->checks_state)->toBeNull()
        ->and(GitWebhookDelivery::query()->where('event', 'action_run_success')->sole()->processed_at)->not->toBeNull();
});

it('asks nothing about a run of a repository no project links', function () {
    Http::fake();

    hookSend($this->connection, 'action_run_failure', hookActionRun('failure', repository: hookRepository(777, 'sdoc', 'other')))->assertStatus(202);

    Http::assertNothingSent();
});

it("keeps a pull request's head commit", function () {
    hookSend($this->connection, 'pull_request', hookPullRequest('opened'))->assertStatus(202);

    expect(GitPullRequest::query()->sole()->head_sha)->toBe('a1b2c3d4e5f60718293a4b5c6d7e8f9001122334');
});
