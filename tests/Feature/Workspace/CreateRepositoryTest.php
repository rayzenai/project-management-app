<?php

use App\Jobs\SyncRepositoryAccess;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\Git\MainBranch;
use App\Services\Git\RepositoryAccess;
use App\Services\Workspace\CreateRepositoryService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * Creating a repository from a project's Code tab: made on the forge
 * (private, empty, `main` as its default branch), `main` protected, then
 * linked as any repository is. Forge fixtures follow Forgejo 15's
 * api.Repository (modules/structs/repo.go) and api.BranchProtection
 * (repo_branch.go); stray requests fail the test, so every forge call is one
 * a stub expects. Jobs are faked: the access sync and the backfill the link
 * queues are tested elsewhere.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com'], 'app.url' => 'https://tasks.example.test']);
    Http::preventStrayRequests();
    Queue::fake();

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
    $this->url = "/workspace/projects/{$this->project->slug}/repositories/new";
});

/**
 * Someone in a role on the project: its manager, its program's manager, a
 * member of its team, its organization's office admin, a super-admin, or
 * another organization's office admin.
 */
function newRepoActor(string $role, Project $project): User
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

    if ($role === 'program manager') {
        $program = Program::factory()->create(['organization_id' => $project->organization_id, 'manager_id' => $member->id]);
        $project->forceFill(['program_id' => $program->id])->save();
    }

    return $user;
}

/** @return array<string, mixed> api.Repository as the forge answers for a repository it has just made */
function newRepoForge(string $name, int $id = 701): array
{
    return [
        'id' => $id,
        'owner' => ['id' => 9, 'login' => 'sdoc', 'full_name' => 'SDOC', 'username' => 'sdoc'],
        'name' => $name,
        'full_name' => "sdoc/{$name}",
        'description' => '',
        'empty' => true,
        'private' => true,
        'fork' => false,
        'mirror' => false,
        'size' => 0,
        'html_url' => "https://git.example/sdoc/{$name}",
        'url' => "https://git.example/api/v1/repos/sdoc/{$name}",
        'ssh_url' => "git@git.example:sdoc/{$name}.git",
        'clone_url' => "https://git.example/sdoc/{$name}.git",
        'default_branch' => 'main',
        'archived' => false,
        'has_issues' => true,
        'has_pull_requests' => true,
        'permissions' => ['admin' => true, 'push' => true, 'pull' => true],
    ];
}

/**
 * The forge making sdoc/{$name}: it creates it, has no rule on its main yet
 * and accepts the new one, describes it, has no collaborators on it and
 * takes the link back, except what $stubs replace.
 *
 * @param  array<string, mixed>  $stubs
 */
function newRepoFakeForge(string $name = 'tp-api', array $stubs = []): void
{
    Http::fake(array_merge([
        'git.example/api/v1/orgs/sdoc/repos' => Http::response(newRepoForge($name), 201),
        "git.example/api/v1/repos/sdoc/{$name}/branch_protections/main" => Http::response(['message' => 'The target couldn\'t be found.'], 404),
        "git.example/api/v1/repos/sdoc/{$name}/branch_protections" => Http::response(['rule_name' => 'main', 'branch_name' => 'main'], 201),
        "git.example/api/v1/repos/sdoc/{$name}" => Http::response(newRepoForge($name)),
        "git.example/api/v1/repos/sdoc/{$name}/collaborators*" => Http::response([]),
    ], $stubs));
}

/**
 * The `main` rule, written out: nobody pushes, admins included; a pull
 * request merges once every check passed and someone approved it.
 *
 * @return array<string, mixed>
 */
function newRepoMainRule(): array
{
    return [
        'rule_name' => 'main',
        'branch_name' => 'main',
        'enable_push' => false,
        'enable_push_whitelist' => false,
        'push_whitelist_usernames' => [],
        'push_whitelist_teams' => [],
        'enable_merge_whitelist' => false,
        'enable_status_check' => true,
        'status_check_contexts' => ['*'],
        'required_approvals' => 1,
        'dismiss_stale_approvals' => true,
        'block_on_rejected_reviews' => true,
        'apply_to_admins' => true,
    ];
}

// Creating

it('lets a project manager create a repository: private, with a README, main protected, linked', function () {
    newRepoFakeForge();

    $this->actingAs(newRepoActor('manager', $this->project))
        ->post($this->url, ['name' => 'tp-api', 'description' => 'The Tax Portal API'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Created and linked sdoc/tp-api.']);

    $sent = Http::recorded()->map(fn (array $pair): string => $pair[0]->method().' '.$pair[0]->url())->all();
    expect(array_slice($sent, 0, 4))->toBe([
        'POST https://git.example/api/v1/orgs/sdoc/repos',
        'PATCH https://git.example/api/v1/repos/sdoc/tp-api/branch_protections/main',
        'POST https://git.example/api/v1/repos/sdoc/tp-api/branch_protections',
        'GET https://git.example/api/v1/repos/sdoc/tp-api',
    ]);
    // main starts from a README: nobody can push a first commit to it.
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://git.example/api/v1/orgs/sdoc/repos'
        && $r->data() == ['name' => 'tp-api', 'description' => 'The Tax Portal API', 'private' => true, 'auto_init' => true, 'readme' => 'Default', 'default_branch' => 'main']
        && $r->hasHeader('Authorization', 'token bot-token-never-shown'));
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://git.example/api/v1/repos/sdoc/tp-api/branch_protections'
        && $r->data() == newRepoMainRule());
    expect(['rule_name' => 'main', 'branch_name' => 'main', ...MainBranch::RULE])->toEqual(newRepoMainRule());

    $repo = GitRepository::query()->sole();
    expect($repo->only(['git_connection_id', 'project_id', 'forge_id', 'full_name', 'default_branch', 'protects_main']))->toEqual([
        'git_connection_id' => $this->connection->id,
        'project_id' => $this->project->id,
        'forge_id' => 701,
        'full_name' => 'sdoc/tp-api',
        'default_branch' => 'main',
        'protects_main' => true,
    ]);
    // The link's own sync, and no second one.
    Queue::assertPushed(SyncRepositoryAccess::class, 1);
    Queue::assertPushed(SyncRepositoryAccess::class, fn (SyncRepositoryAccess $job) => $job->repository->is($repo) && $job->repository->protects_main);
});

it('makes the manager an admin and keeps main\'s rule when the link\'s access sync runs', function () {
    newRepoFakeForge(stubs: [
        'git.example/api/v1/repos/sdoc/tp-api/collaborators/mira' => Http::response('', 204),
        'git.example/api/v1/repos/sdoc/tp-api/branch_protections/main' => Http::response(['rule_name' => 'main']),
    ]);
    $manager = newRepoActor('manager', $this->project);
    $manager->forceFill(['git_username' => 'mira'])->save();

    $this->actingAs($manager)->post($this->url, ['name' => 'tp-api'])->assertSessionHasNoErrors();
    Queue::pushed(SyncRepositoryAccess::class)->sole()->handle(app(RepositoryAccess::class));

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://git.example/api/v1/repos/sdoc/tp-api/collaborators/mira'
        && $r->data() === ['permission' => 'admin']);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === 'https://git.example/api/v1/repos/sdoc/tp-api/branch_protections/main'
        && $r->data() === MainBranch::RULE);
});

it('lets the program manager, the office admin and a super-admin create one too', function (string $role) {
    newRepoFakeForge();

    $this->actingAs(newRepoActor($role, $this->project))
        ->post($this->url, ['name' => 'tp-api'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash.message', 'Created and linked sdoc/tp-api.');

    expect(GitRepository::query()->sole()->project_id)->toBe($this->project->id);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://git.example/api/v1/orgs/sdoc/repos' && $r->data()['description'] === '');
})->with(['program manager', 'office admin', 'super-admin']);

// The name

it('takes the key itself as the name', function () {
    newRepoFakeForge('tp');

    $this->actingAs(newRepoActor('manager', $this->project))
        ->post($this->url, ['name' => 'tp'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash.message', 'Created and linked sdoc/tp.');

    expect(GitRepository::query()->sole()->full_name)->toBe('sdoc/tp');
});

it('refuses names that don\'t start with the key', function (string $name) {
    $this->actingAs(newRepoActor('manager', $this->project))
        ->post($this->url, ['name' => $name])
        ->assertSessionHasErrors(['name' => 'Start the name with tp, such as tp-api.']);

    expect(GitRepository::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['api', 'tpx', 'tp_api', 'tp.api']);

it('refuses names the forge would not take', function (mixed $name) {
    $this->actingAs(newRepoActor('manager', $this->project))
        ->post($this->url, ['name' => $name])
        ->assertSessionHasErrors('name');

    Http::assertNothingSent();
})->with([
    'missing' => [null],
    'capitals' => ['TP-Api'],
    'two separators' => ['tp--api'],
    'separators side by side' => ['tp-.x'],
    'ending in a separator' => ['tp-'],
    'a space' => ['tp api'],
    'a path' => ['tp/api'],
    '.git' => ['tp-api.git'],
    '.wiki' => ['tp-api.wiki'],
    '.rss' => ['tp-api.rss'],
    '.atom' => ['tp-api.atom'],
    '101 characters' => ['tp-'.str_repeat('a', 98)],
]);

it('says what a well-formed name is', function () {
    $this->actingAs(newRepoActor('manager', $this->project))
        ->post($this->url, ['name' => 'TP-Api'])
        ->assertSessionHasErrors(['name' => 'Use lowercase letters and digits, with a single ., _ or - between them.']);

    $this->post($this->url, ['name' => 'tp-api.git'])
        ->assertSessionHasErrors(['name' => "The name can't end in .git, .wiki, .rss or .atom."]);
});

it('takes a name of 100 characters', function () {
    $name = 'tp-'.str_repeat('a', 97);
    newRepoFakeForge($name);

    $this->actingAs(newRepoActor('manager', $this->project))
        ->post($this->url, ['name' => $name])
        ->assertSessionHasNoErrors();

    expect(GitRepository::query()->sole()->name)->toBe($name);
});

it('refuses a description over 255 characters', function () {
    $this->actingAs(newRepoActor('manager', $this->project))
        ->post($this->url, ['name' => 'tp-api', 'description' => str_repeat('a', 256)])
        ->assertSessionHasErrors('description');

    Http::assertNothingSent();
});

// The forge refuses

it('says to link a name the forge organization already has', function () {
    newRepoFakeForge(stubs: ['git.example/api/v1/orgs/sdoc/repos' => Http::response(['message' => 'The repository with the same name already exists.'], 409)]);

    $this->actingAs(newRepoActor('manager', $this->project))
        ->post($this->url, ['name' => 'tp-api'])
        ->assertSessionHasErrors(['__global' => 'A repository named tp-api already exists in sdoc. Link it instead.']);

    expect(GitRepository::query()->count())->toBe(0);
    Http::assertSentCount(1);
    Queue::assertNothingPushed();
});

it('words other refusals to create as linking does, and saves nothing', function (string $failure, string $message) {
    Log::spy();
    newRepoFakeForge(stubs: ['git.example/api/v1/orgs/sdoc/repos' => match ($failure) {
        'down' => fn () => throw new ConnectionException('cURL error 7: Failed to connect with bot-token-never-shown'),
        'broken' => Http::response(['message' => 'boom'], 500),
        'refused' => Http::response(['message' => 'token does not have at least one of required scope(s): [write:organization]'], 403),
    }]);

    $this->actingAs(newRepoActor('manager', $this->project))
        ->post($this->url, ['name' => 'tp-api'])
        ->assertSessionHasErrors(['__global' => $message]);

    expect(GitRepository::query()->count())->toBe(0);
    // An unreachable forge records no request at all.
    Http::assertNotSent(fn (Request $r) => $r->url() !== 'https://git.example/api/v1/orgs/sdoc/repos');
    Queue::assertNothingPushed();
    if ($failure === 'down') {
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Forge unreachable.'
            && str_contains($context['cause'], 'cURL error 7')
            && ! str_contains(json_encode($context), 'bot-token-never-shown'))->once();
    }
})->with([
    'down' => ['down', "Couldn't reach the forge at https://git.example."],
    'broken' => ['broken', "Couldn't reach the forge at https://git.example."],
    'refused' => ['refused', 'The forge answered 403. Try again shortly.'],
]);

it('links a repository whose main couldn\'t be protected, and says so', function (string $failure, int $status) {
    Log::spy();
    newRepoFakeForge(stubs: ['git.example/api/v1/repos/sdoc/tp-api/branch_protections' => $failure === 'down'
        ? fn () => throw new ConnectionException('cURL error 28: timed out after bot-token-never-shown')
        : Http::response(['message' => 'boom'], 500)]);

    $this->actingAs(newRepoActor('manager', $this->project))
        ->post($this->url, ['name' => 'tp-api'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => "Created and linked sdoc/tp-api, but main couldn't be protected yet; the tracker keeps trying."]);

    $repo = GitRepository::query()->sole();
    expect($repo->protects_main)->toBeFalse();
    Queue::assertPushed(SyncRepositoryAccess::class, 1);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === "Couldn't protect a new forge repository's main branch."
        && $context['repository'] === 'sdoc/tp-api'
        && $context['status'] === $status
        && ! str_contains(json_encode($context), 'bot-token-never-shown'))->once();
})->with([
    'refused' => ['refused', 500],
    'down' => ['down', 0],
]);

it('says the repository exists when it can\'t be linked after the forge made it', function () {
    newRepoFakeForge(stubs: ['git.example/api/v1/repos/sdoc/tp-api' => Http::response(['message' => 'boom'], 500)]);

    $this->actingAs(newRepoActor('manager', $this->project))
        ->post($this->url, ['name' => 'tp-api'])
        ->assertSessionHasErrors(['__global' => "Created sdoc/tp-api on the forge, but couldn't link it: Couldn't reach the forge at https://git.example."]);

    expect(GitRepository::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

// Who may not

it('refuses a team member and another organization\'s office admin', function (string $role) {
    $this->actingAs(newRepoActor($role, $this->project))
        ->post($this->url, ['name' => 'tp-api'])
        ->assertForbidden();

    expect(GitRepository::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['member', 'outside admin']);

it('answers 403, even to a super-admin, when the organization has no git', function () {
    $this->connection->delete();

    $this->actingAs(newRepoActor('super-admin', $this->project))
        ->post($this->url, ['name' => 'tp-api'])
        ->assertForbidden();

    Http::assertNothingSent();
});

it('refuses to create anything for an organization without git', function () {
    $this->connection->delete();

    $result = app(CreateRepositoryService::class)->execute($this->project, 'tp-api', null, null);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe("Git isn't on for this organization.")
        ->and($result->code)->toBe(422);
    Http::assertNothingSent();
});
