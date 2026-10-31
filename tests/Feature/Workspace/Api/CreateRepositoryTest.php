<?php

use App\Jobs\SyncRepositoryAccess;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
 * The JSON twin of CreateRepositoryTest, through Sanctum. Forge fixtures
 * follow Forgejo 15's api.Repository; stray requests fail the test.
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
    $this->url = "/api/v1/workspace/projects/{$this->project->slug}/repositories/new";
});

/** Signs in, through Sanctum, someone in a role on the project (see newRepoActor in the web twin). */
function apiNewRepoActor(string $role, Project $project): User
{
    if ($role === 'super-admin') {
        $user = User::factory()->create(['email' => 'boss@example.com']);
    } else {
        $user = User::factory()->create();
        $member = Member::factory()->linkedTo($user)->create([
            'organization_id' => $role === 'outside admin' ? Organization::factory()->create()->id : $project->organization_id,
            'is_office_admin' => in_array($role, ['office admin', 'outside admin'], true),
        ]);

        if (in_array($role, ['manager', 'member'], true)) {
            $project->ensureTeam()->members()->attach($member->id, ['role' => $role === 'manager' ? 'leader' : 'member']);
        }
    }

    Sanctum::actingAs($user, ['*']);

    return $user;
}

/** @return array<string, mixed> api.Repository of a repository the forge has just made */
function apiNewRepoForge(string $name = 'tp-api'): array
{
    return [
        'id' => 701,
        'owner' => ['id' => 9, 'login' => 'sdoc', 'username' => 'sdoc'],
        'name' => $name,
        'full_name' => "sdoc/{$name}",
        'description' => '',
        'empty' => true,
        'private' => true,
        'html_url' => "https://git.example/sdoc/{$name}",
        'ssh_url' => "git@git.example:sdoc/{$name}.git",
        'clone_url' => "https://git.example/sdoc/{$name}.git",
        'default_branch' => 'main',
        'has_issues' => true,
    ];
}

/** @param  array<string, mixed>  $stubs */
function apiNewRepoFakeForge(array $stubs = []): void
{
    Http::fake(array_merge([
        'git.example/api/v1/orgs/sdoc/repos' => Http::response(apiNewRepoForge(), 201),
        'git.example/api/v1/repos/sdoc/tp-api/branch_protections/main' => Http::response(['message' => 'The target couldn\'t be found.'], 404),
        'git.example/api/v1/repos/sdoc/tp-api/branch_protections' => Http::response(['rule_name' => 'main'], 201),
        'git.example/api/v1/repos/sdoc/tp-api/branches/main' => Http::response(['name' => 'main']),
        'git.example/api/v1/repos/sdoc/tp-api' => Http::response(apiNewRepoForge()),
        'git.example/api/v1/repos/sdoc/tp-api/collaborators*' => Http::response([]),
    ], $stubs));
}

it('creates, protects and links a repository, answering 201 with it', function () {
    apiNewRepoFakeForge();
    apiNewRepoActor('manager', $this->project);

    $response = $this->postJson($this->url, ['name' => 'tp-api', 'description' => 'The Tax Portal API'])->assertCreated();

    $repo = GitRepository::query()->sole();
    expect($response->json())->toEqual([
        'message' => 'Created and linked sdoc/tp-api.',
        'data' => [
            'id' => $repo->id,
            'owner' => 'sdoc',
            'name' => 'tp-api',
            'full_name' => 'sdoc/tp-api',
            'html_url' => 'https://git.example/sdoc/tp-api',
            'clone_url' => 'https://git.example/sdoc/tp-api.git',
            'ssh_url' => 'git@git.example:sdoc/tp-api.git',
            'default_branch' => 'main',
            'protected_branch' => 'main',
            'last_pushed_at' => null,
            'created_at' => $repo->created_at?->toIso8601String(),
            'protects_main' => true,
            'main_checks' => null,
        ],
    ]);
    expect($repo->protects_main)->toBeTrue();
    expect($response->getContent())->not->toContain('bot-token-never-shown')->not->toContain('hook-secret-never-shown');
    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && $r->url() === 'https://git.example/api/v1/orgs/sdoc/repos'
        && $r->data() == ['name' => 'tp-api', 'description' => 'The Tax Portal API', 'private' => true, 'auto_init' => true, 'readme' => 'Default', 'default_branch' => 'main']);
    Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://git.example/api/v1/repos/sdoc/tp-api/branch_protections');
    Queue::assertPushed(SyncRepositoryAccess::class, 1);
});

it('lets the office admin and a super-admin create one too', function (string $role) {
    apiNewRepoFakeForge();
    apiNewRepoActor($role, $this->project);

    $this->postJson($this->url, ['name' => 'tp-api'])
        ->assertCreated()
        ->assertJsonPath('data.full_name', 'sdoc/tp-api');
})->with(['office admin', 'super-admin']);

it('refuses a name that doesn\'t start with the key with 422', function () {
    apiNewRepoActor('manager', $this->project);

    $this->postJson($this->url, ['name' => 'api'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name' => 'Start the name with tp, such as tp-api.']);

    Http::assertNothingSent();
});

it('refuses a name the forge would not take with 422', function (string $name) {
    apiNewRepoActor('manager', $this->project);

    $this->postJson($this->url, ['name' => $name])->assertStatus(422)->assertJsonValidationErrors('name');

    Http::assertNothingSent();
})->with(['TP-Api', 'tp--api', 'tp-api.git', 'tp-.x']);

it('says to link a name the forge organization already has, with 422', function () {
    apiNewRepoFakeForge(['git.example/api/v1/orgs/sdoc/repos' => Http::response(['message' => 'The repository with the same name already exists.'], 409)]);
    apiNewRepoActor('manager', $this->project);

    $this->postJson($this->url, ['name' => 'tp-api'])
        ->assertStatus(422)
        ->assertExactJson([
            'message' => 'A repository named tp-api already exists in sdoc. Link it instead.',
            'errors' => ['__global' => 'A repository named tp-api already exists in sdoc. Link it instead.'],
        ]);

    expect(GitRepository::query()->count())->toBe(0);
});

it('still answers 201 when main couldn\'t be protected, and says so', function () {
    apiNewRepoFakeForge(['git.example/api/v1/repos/sdoc/tp-api/branch_protections' => Http::response(['message' => 'boom'], 500)]);
    apiNewRepoActor('manager', $this->project);

    $this->postJson($this->url, ['name' => 'tp-api'])
        ->assertCreated()
        ->assertJsonPath('message', "Created and linked sdoc/tp-api, but main couldn't be protected yet; the tracker keeps trying.")
        ->assertJsonPath('data.full_name', 'sdoc/tp-api');

    expect(GitRepository::query()->sole()->protects_main)->toBeFalse();
});

it('answers 422 saying the repository exists when the link fails after the forge made it', function () {
    apiNewRepoFakeForge(['git.example/api/v1/repos/sdoc/tp-api' => Http::response(['message' => 'boom'], 500)]);
    apiNewRepoActor('manager', $this->project);

    $this->postJson($this->url, ['name' => 'tp-api'])
        ->assertStatus(422)
        ->assertJsonPath('message', "Created sdoc/tp-api on the forge, but couldn't link it: Couldn't reach the forge at https://git.example.");

    expect(GitRepository::query()->count())->toBe(0);
});

it('refuses a team member and another organization\'s office admin', function (string $role) {
    apiNewRepoActor($role, $this->project);

    $this->postJson($this->url, ['name' => 'tp-api'])->assertForbidden();

    Http::assertNothingSent();
})->with(['member', 'outside admin']);

it('answers 403, even to a super-admin, when the organization has no git', function () {
    $this->connection->delete();
    apiNewRepoActor('super-admin', $this->project);

    $this->postJson($this->url, ['name' => 'tp-api'])->assertForbidden();

    Http::assertNothingSent();
});
