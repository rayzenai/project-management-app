<?php

use App\Jobs\BackfillGitRepository;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
 * The JSON twin of ProjectRepositoryTest: the same matrix through Sanctum.
 * Forge fixtures follow Forgejo 15's api.Repository and api.Commit.
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
    $this->base = "/api/v1/workspace/projects/{$this->project->slug}/repositories";
});

/** Signs in, through Sanctum, someone in a role on the project (see repoActor in the web twin). */
function apiRepoActor(string $role, Project $project): User
{
    if ($role === 'super-admin') {
        $user = User::factory()->create(['email' => 'boss@example.com']);
    } else {
        $user = User::factory()->create();
        $member = Member::factory()->linkedTo($user)->create([
            'organization_id' => in_array($role, ['outside admin', 'outsider'], true) ? Organization::factory()->create()->id : $project->organization_id,
            'is_office_admin' => in_array($role, ['office admin', 'outside admin'], true),
        ]);

        if (in_array($role, ['manager', 'member'], true)) {
            $project->ensureTeam()->members()->attach($member->id, ['role' => $role === 'manager' ? 'leader' : 'member']);
        }
    }

    Sanctum::actingAs($user, ['*']);

    return $user;
}

function apiRepoLinked(Project $project, GitConnection $connection, int $forgeId = 501, string $name = 'app'): GitRepository
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

/** @return array<string, mixed> api.Repository */
function apiRepoForge(int $id = 501, string $owner = 'sdoc', string $name = 'app'): array
{
    return [
        'id' => $id,
        'owner' => ['id' => 9, 'login' => $owner, 'username' => $owner],
        'name' => $name,
        'full_name' => "{$owner}/{$name}",
        'description' => '',
        'empty' => false,
        'private' => true,
        'html_url' => "https://git.example/{$owner}/{$name}",
        'ssh_url' => "git@git.example:{$owner}/{$name}.git",
        'clone_url' => "https://git.example/{$owner}/{$name}.git",
        'default_branch' => 'main',
        'has_issues' => true,
    ];
}

/** @param  list<array<string, mixed>>  $rows */
function apiRepoPage(array $rows): Closure
{
    return function (ClientRequest $request) use ($rows) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(($query['page'] ?? '1') === '1' ? $rows : []);
    };
}

/** @param  array<string, mixed>  $stubs */
function apiRepoFakeForge(array $stubs = []): void
{
    Http::fake(array_merge([
        'git.example/api/v1/orgs/sdoc/repos*' => apiRepoPage([apiRepoForge(), apiRepoForge(502, 'sdoc', 'api')]),
        'git.example/api/v1/repos/sdoc/app' => Http::response(apiRepoForge()),
        'git.example/api/v1/repos/sdoc/app/pulls*' => apiRepoPage([]),
        'git.example/api/v1/repos/sdoc/app/branches*' => apiRepoPage([]),
        'git.example/api/v1/repos/sdoc/app/commits*' => apiRepoPage([]),
        'git.example/api/v1/repos/sdoc/app/collaborators*' => apiRepoPage([]),
    ], $stubs));
}

/** @return array<string, mixed> the GitRepositoryResource of sdoc/app */
function apiRepoResource(GitRepository $repo): array
{
    return [
        'id' => $repo->id,
        'owner' => 'sdoc',
        'name' => 'app',
        'full_name' => 'sdoc/app',
        'html_url' => 'https://git.example/sdoc/app',
        'clone_url' => 'https://git.example/sdoc/app.git',
        'ssh_url' => 'git@git.example:sdoc/app.git',
        'default_branch' => 'main',
        'protected_branch' => 'main',
        'last_pushed_at' => $repo->last_pushed_at?->toIso8601String(),
        'created_at' => $repo->created_at?->toIso8601String(),
        'protects_main' => (bool) $repo->protects_main,
        'main_checks' => null,
    ];
}

// Listing

it('lists a project\'s repositories to anyone who can see the project', function (string $role) {
    // On the team before the link: joining a team with repositories syncs their access.
    apiRepoActor($role, $this->project);
    $repo = apiRepoLinked($this->project, $this->connection);
    $repo->forceFill(['last_pushed_at' => now()->subHour()])->save();

    $response = $this->getJson($this->base)->assertOk();

    expect($response->json())->toEqual(['message' => 'Repositories retrieved.', 'data' => [apiRepoResource($repo->fresh())]]);
    expect($response->getContent())->not->toContain('bot-token-never-shown')->not->toContain('hook-secret-never-shown');
})->with(['member', 'manager', 'office admin', 'super-admin']);

it("gives each listed repository its main branch's checks", function () {
    apiRepoActor('member', $this->project);
    $repo = apiRepoLinked($this->project, $this->connection);
    $repo->commits()->create(['sha' => 'aaa', 'message' => 'Work', 'author_name' => 'Asha', 'url' => 'https://git.example/sdoc/app/commit/aaa', 'committed_at' => now()])
        ->forceFill(['checks_state' => 'passed', 'checks' => [['name' => 'tests (push)', 'state' => 'passed', 'url' => null]]])->save();
    $repo->branches()->create(['name' => 'main', 'head_sha' => 'aaa']);

    // jsonb keeps no key order: compare by value.
    expect($this->getJson($this->base)->assertOk()->json('data.0.main_checks'))
        ->toEqual(['state' => 'passed', 'checks' => [['name' => 'tests (push)', 'state' => 'passed', 'url' => null]]]);
});

it('keeps the repositories from someone who can\'t see the project', function (string $role) {
    apiRepoLinked($this->project, $this->connection);
    if ($role === 'colleague off the team') {
        $user = User::factory()->create();
        Member::factory()->linkedTo($user)->create(['organization_id' => $this->organization->id]);
        Sanctum::actingAs($user);
    } else {
        apiRepoActor($role, $this->project);
    }

    $this->getJson($this->base)->assertForbidden();
})->with(['outsider', 'outside admin', 'colleague off the team']);

// Who may

it('lets the manager, the office admin and a super-admin link, search and unlink', function (string $role) {
    Queue::fake();
    apiRepoFakeForge();
    apiRepoActor($role, $this->project);

    $this->getJson("{$this->base}/candidates?q=AP")->assertOk()->assertJsonPath('data.*.full_name', ['sdoc/api', 'sdoc/app']);

    $response = $this->postJson($this->base, ['full_name' => 'sdoc/app'])->assertCreated();
    $repo = GitRepository::query()->sole();
    expect($response->json())->toEqual(['message' => 'Linked sdoc/app.', 'data' => apiRepoResource($repo)]);
    Queue::assertPushed(BackfillGitRepository::class, fn (BackfillGitRepository $job) => $job->repository->is($repo));

    $this->deleteJson("{$this->base}/{$repo->id}")
        ->assertOk()
        ->assertExactJson(['message' => "Unlinked sdoc/app. The repository is untouched on the forge. People who had access keep it on the forge until it's changed there or the repository is linked again.", 'data' => null]);
    expect(GitRepository::query()->count())->toBe(0);
})->with(['manager', 'office admin', 'super-admin']);

it('refuses a team member and another organization\'s office admin on every change', function (string $role) {
    Http::fake();
    // On the team before the link: joining a team with repositories syncs their access.
    apiRepoActor($role, $this->project);
    $repo = apiRepoLinked($this->project, $this->connection);

    $this->getJson("{$this->base}/candidates")->assertForbidden();
    $this->postJson($this->base, ['full_name' => 'sdoc/app'])->assertForbidden();
    $this->deleteJson("{$this->base}/{$repo->id}")->assertForbidden();

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
    $stray = apiRepoLinked($this->project, $elsewhere);
    $this->connection->delete();
    apiRepoActor('super-admin', $this->project);

    $this->getJson($this->base)->assertForbidden();
    $this->getJson("{$this->base}/candidates")->assertForbidden();
    $this->postJson($this->base, ['full_name' => 'sdoc/app'])->assertForbidden();
    $this->deleteJson("{$this->base}/{$stray->id}")->assertForbidden();

    expect($stray->fresh())->not->toBeNull();
    Http::assertNothingSent();
});

// Refusals

it('refuses a repository of another forge organization with 422', function () {
    Queue::fake();
    Http::fake(['git.example/api/v1/repos/moha/app' => Http::response(apiRepoForge(601, 'moha', 'app'))]);
    apiRepoActor('manager', $this->project);

    $this->postJson($this->base, ['full_name' => 'moha/app'])
        ->assertStatus(422)
        ->assertExactJson([
            'message' => "That repository isn't in the sdoc organization on the forge.",
            'errors' => ['__global' => "That repository isn't in the sdoc organization on the forge."],
        ]);

    expect(GitRepository::query()->count())->toBe(0);
});

it('refuses a repository already linked to any project with 422', function () {
    Queue::fake();
    apiRepoFakeForge();
    apiRepoLinked(Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'SMS Gateway', 'key' => 'SG']), $this->connection);
    apiRepoActor('office admin', $this->project);

    $this->postJson($this->base, ['full_name' => 'sdoc/app'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'That repository is already linked to SMS Gateway.');

    $this->getJson("{$this->base}/candidates")->assertOk()->assertJsonPath('data.*.full_name', ['sdoc/api']);
    expect(GitRepository::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('validates the repository name', function () {
    Http::fake();
    apiRepoActor('manager', $this->project);

    $this->postJson($this->base, ['full_name' => 'sdoc'])->assertStatus(422)->assertJsonValidationErrors('full_name');

    Http::assertNothingSent();
});

it('still links, with 201, when the forge refuses the link back', function () {
    Queue::fake();
    apiRepoFakeForge([
        'git.example/api/v1/repos/sdoc/app' => fn (ClientRequest $r) => $r->method() === 'PATCH' ? Http::response([], 403) : Http::response(apiRepoForge()),
    ]);
    apiRepoActor('manager', $this->project);

    $this->postJson($this->base, ['full_name' => 'sdoc/app'])
        ->assertCreated()
        ->assertJsonPath('message', "Linked sdoc/app. The forge link back couldn't be set; the nightly reconcile will retry.")
        ->assertJsonPath('data.full_name', 'sdoc/app');

    Queue::assertPushed(BackfillGitRepository::class);
});

it('unlinks, handing the forge issue tracker back and touching nothing else on the forge', function () {
    Http::fake();
    $repo = apiRepoLinked($this->project, $this->connection);
    apiRepoActor('manager', $this->project);

    $this->deleteJson("{$this->base}/{$repo->id}")->assertOk();

    expect($repo->fresh())->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'PATCH' && $r->url() === 'https://git.example/api/v1/repos/sdoc/app' && $r->data() === ['has_issues' => true]);
});

it('unlinks even when the forge refuses to hand the issue tracker back', function () {
    Http::fake(['git.example/*' => Http::response([], 500)]);
    $repo = apiRepoLinked($this->project, $this->connection);
    apiRepoActor('manager', $this->project);

    $this->deleteJson("{$this->base}/{$repo->id}")->assertOk();

    expect($repo->fresh())->toBeNull();
});

it('never puts the bot token or the webhook secret in a response', function () {
    Queue::fake();
    apiRepoFakeForge();
    apiRepoActor('super-admin', $this->project);

    $bodies = [
        $this->getJson("{$this->base}/candidates")->assertOk()->getContent(),
        $this->postJson($this->base, ['full_name' => 'sdoc/app'])->assertCreated()->getContent(),
        $this->getJson($this->base)->assertOk()->getContent(),
    ];

    foreach ($bodies as $body) {
        expect($body)->not->toContain('bot-token-never-shown')->not->toContain('hook-secret-never-shown');
    }
});

it('on the sync queue, answers the link first and backfills once the response is sent', function () {
    apiRepoFakeForge(['git.example/api/v1/repos/sdoc/app/commits*' => apiRepoPage([[
        'sha' => 'c1c2c3d4e5f60718293a4b5c6d7e8f9001122334',
        'html_url' => 'https://git.example/sdoc/app/commit/c1c2c3d4e5f60718293a4b5c6d7e8f9001122334',
        'commit' => [
            'author' => ['name' => 'Bikash Dev', 'email' => 'bikash@example.com', 'date' => now()->subDay()->toRfc3339String()],
            'committer' => ['name' => 'Bikash Dev', 'email' => 'bikash@example.com', 'date' => now()->subDay()->toRfc3339String()],
            'message' => "TP-1 fix\n",
        ],
        'author' => ['id' => 12, 'login' => 'bikash'],
    ]])]);
    apiRepoActor('manager', $this->project);
    $request = Request::create($this->base, 'POST', ['full_name' => 'sdoc/app'], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $kernel = app(HttpKernel::class);

    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(201)
        ->and($this->task->commits()->count())->toBe(0);

    $kernel->terminate($request, $response);

    expect($this->task->commits()->count())->toBe(1);
});
