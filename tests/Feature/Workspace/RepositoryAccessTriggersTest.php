<?php

use App\Jobs\BackfillGitRepository;
use App\Jobs\SyncRepositoryAccess;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\GitRepositoryGrant;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\User;
use App\Services\Workspace\AppointProjectManagerService;
use App\Services\Workspace\RemoveTeamMemberService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

/*
 * What starts an access sync (SyncRepositoryAccess) of a linked repository:
 * a grant made, changed or removed; the project's team changing; its
 * program's manager changing; the project moving between programs; linking
 * it. Jobs go to a faked worker queue, so Background::dispatch pushes them at
 * once and nothing reaches the forge unless a test fakes it. Forge fixtures
 * follow Forgejo 15's API structs, as ProjectRepositoryTest's do.
 *
 * Tax Portal (SDOC, key TP) is in the program Revenue, managed by Pradeep;
 * Mira manages the project. sdoc/tp-api and sdoc/tp-web are linked to it,
 * sdoc/sms to another project.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com'], 'app.url' => 'https://tasks.example.test', 'queue.default' => 'database']);
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

    [, $this->programManager] = trigPerson($this->organization, 'Pradeep');
    $this->program = Program::factory()->create(['organization_id' => $this->organization->id, 'manager_id' => $this->programManager->id, 'name' => 'Revenue']);
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'program_id' => $this->program->id, 'title' => 'Tax Portal', 'key' => 'TP']);
    $this->team = $this->project->ensureTeam();
    [$this->managerUser, $this->manager] = trigPerson($this->organization, 'Mira');
    $this->team->members()->attach($this->manager->id, ['role' => 'leader']);
    [, $this->asha] = trigPerson($this->organization, 'Asha');
    [$this->adminUser] = trigPerson($this->organization, 'Kamala', admin: true);

    $this->other = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'SMS Gateway', 'key' => 'SG']);
    $this->api = trigLinked($this->project, 'tp-api', 501);
    $this->web = trigLinked($this->project, 'tp-web', 502);
    $this->sms = trigLinked($this->other, 'sms', 503);
});

/** @return array{0: User, 1: Member} */
function trigPerson(Organization $organization, string $name, bool $admin = false): array
{
    $user = User::factory()->create(['name' => $name, 'git_username' => mb_strtolower($name)]);
    $member = Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id, 'is_office_admin' => $admin]);

    return [$user, $member];
}

function trigLinked(Project $project, string $name, int $forgeId): GitRepository
{
    return GitRepository::query()->create([
        'git_connection_id' => GitConnection::query()->where('owner', 'sdoc')->sole()->id,
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

function trigGrant(GitRepository $repo, Member $member, string $permission = GitRepositoryGrant::WRITE): GitRepositoryGrant
{
    return GitRepositoryGrant::query()->create([
        'git_repository_id' => $repo->id,
        'member_id' => $member->id,
        'permission' => $permission,
    ]);
}

/**
 * The full names of the repositories a sync was queued for, in order.
 *
 * @return list<string>
 */
function trigSynced(): array
{
    return Queue::pushed(SyncRepositoryAccess::class)
        ->map(fn (SyncRepositoryAccess $job): string => $job->repository->full_name)
        ->values()
        ->all();
}

/** @return list<string> */
function trigSorted(array $values): array
{
    sort($values);

    return array_values($values);
}

/** @return list<array{description: string, task_id: int|null, user_id: int|null}> */
function trigGrantActivity(Project $project): array
{
    return ProjectActivity::query()
        ->where('project_id', $project->id)
        ->where('subject_type', 'git-repository-grant')
        ->orderBy('id')
        ->get()
        ->map(fn (ProjectActivity $a): array => ['description' => $a->description, 'task_id' => $a->task_id, 'user_id' => $a->user_id])
        ->all();
}

// Grants

it('granting, changing and removing access each record project activity and queue a sync', function () {
    $this->actingAs($this->adminUser);

    $grant = trigGrant($this->api, $this->asha);
    $grant->update(['permission' => GitRepositoryGrant::READ]);
    $grant->delete();

    $by = $this->adminUser->id;
    expect(trigGrantActivity($this->project))->toBe([
        ['description' => 'gave Asha write access to sdoc/tp-api', 'task_id' => null, 'user_id' => $by],
        ['description' => "changed Asha's access to sdoc/tp-api to read", 'task_id' => null, 'user_id' => $by],
        ['description' => "removed Asha's access to sdoc/tp-api", 'task_id' => null, 'user_id' => $by],
    ])
        ->and(trigSynced())->toBe(['sdoc/tp-api', 'sdoc/tp-api', 'sdoc/tp-api']);

    $rows = ProjectActivity::query()->where('subject_type', 'git-repository-grant')->orderBy('id')->get();
    expect($rows->pluck('subject_id')->unique()->all())->toBe([$grant->id])
        ->and($rows->pluck('action')->all())->toBe([ProjectActivity::ACTION_CREATED, ProjectActivity::ACTION_UPDATED, ProjectActivity::ACTION_DELETED])
        ->and($rows[1]->changes)->toEqual(['member_id' => $this->asha->id, 'from' => 'write', 'to' => 'read']);
});

it('records nothing and queues nothing when a grant is saved without a new permission', function () {
    $grant = trigGrant($this->api, $this->asha);
    Queue::fake();

    $grant->update(['permission' => GitRepositoryGrant::WRITE]);
    $grant->touch();

    expect(trigGrantActivity($this->project))->toHaveCount(1);
    Queue::assertNotPushed(SyncRepositoryAccess::class);
});

it('shows grant activity only to people who may see the project\'s code', function () {
    $this->project->update(['is_public' => true]);
    trigGrant($this->api, $this->asha);
    [$outsiderUser] = trigPerson($this->organization, 'Uma');

    $mentions = fn ($rows): bool => str_contains((string) json_encode($rows, JSON_UNESCAPED_SLASHES), 'access to sdoc/tp-api');

    $this->actingAs($this->managerUser)->get('/workspace/notifications?tab=activity')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('activity.data', fn ($rows) => $mentions($rows)));
    $this->actingAs($outsiderUser)->get('/workspace/notifications?tab=activity')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('activity.data', fn ($rows) => ! $mentions($rows) && count($rows) > 0));
});

// The team

it('joining a project\'s team queues a sync of its repositories', function () {
    $this->team->members()->attach($this->asha->id, ['role' => 'member']);

    expect(trigSorted(trigSynced()))->toBe(['sdoc/tp-api', 'sdoc/tp-web']);
});

it('becoming its manager queues one', function () {
    $this->team->members()->attach($this->asha->id, ['role' => 'member']);
    Queue::fake();

    app(AppointProjectManagerService::class)->execute($this->team, $this->asha);

    // Mira steps down, Asha steps up: each role change syncs both repositories.
    expect(trigSorted(trigSynced()))->toBe(['sdoc/tp-api', 'sdoc/tp-api', 'sdoc/tp-web', 'sdoc/tp-web']);
});

it('queues nothing when a team pivot is saved without a new role', function () {
    $this->team->members()->attach($this->asha->id, ['role' => 'member']);
    Queue::fake();

    $this->team->members()->updateExistingPivot($this->asha->id, ['role' => 'member']);

    Queue::assertNotPushed(SyncRepositoryAccess::class);
});

it('leaving the team removes that person\'s grants on its repositories and queues a sync', function () {
    $this->team->members()->attach($this->asha->id, ['role' => 'member']);
    trigGrant($this->api, $this->asha);
    trigGrant($this->web, $this->asha, GitRepositoryGrant::READ);
    $elsewhere = trigGrant($this->sms, $this->asha);
    $kept = trigGrant($this->api, $this->manager);
    Queue::fake();
    $this->actingAs($this->adminUser);

    app(RemoveTeamMemberService::class)->execute($this->team, $this->asha);

    expect(GitRepositoryGrant::query()->orderBy('id')->pluck('id')->all())->toBe([$elsewhere->id, $kept->id])
        ->and(array_slice(array_column(trigGrantActivity($this->project), 'description'), -2))->toBe([
            "removed Asha's access to sdoc/tp-api",
            "removed Asha's access to sdoc/tp-web",
        ])
        ->and(trigSorted(array_unique(trigSynced())))->toBe(['sdoc/tp-api', 'sdoc/tp-web'])
        ->and(trigSynced())->not->toContain('sdoc/sms');
});

it('leaving the team through the People tab does the same', function () {
    $this->team->members()->attach($this->asha->id, ['role' => 'member']);
    trigGrant($this->api, $this->asha);
    Queue::fake();

    $this->actingAs($this->adminUser)
        ->delete("/workspace/teams/{$this->team->id}/members/{$this->asha->id}")
        ->assertSessionHasNoErrors();

    expect(GitRepositoryGrant::query()->count())->toBe(0)
        ->and(trigSorted(array_unique(trigSynced())))->toBe(['sdoc/tp-api', 'sdoc/tp-web']);
});

it('queues nothing for a project without linked repositories', function () {
    $team = $this->other->ensureTeam();
    $this->sms->delete();

    $team->members()->attach($this->asha->id, ['role' => 'member']);
    $team->members()->detach($this->asha->id);

    Queue::assertNotPushed(SyncRepositoryAccess::class);
});

// Programs

it('a new program manager queues syncs for the program\'s projects', function () {
    $billing = Project::factory()->create(['organization_id' => $this->organization->id, 'program_id' => $this->program->id, 'title' => 'Billing', 'key' => 'BL']);
    trigLinked($billing, 'bl-api', 504);
    [, $ram] = trigPerson($this->organization, 'Ram');
    Queue::fake();

    $this->program->update(['name' => 'Revenue and Customs']);
    Queue::assertNotPushed(SyncRepositoryAccess::class);

    $this->program->update(['manager_id' => $ram->id]);

    expect(trigSorted(trigSynced()))->toBe(['sdoc/bl-api', 'sdoc/tp-api', 'sdoc/tp-web']);
});

it('moving a project to another program queues a sync', function () {
    $customs = Program::factory()->create(['organization_id' => $this->organization->id, 'manager_id' => $this->manager->id, 'name' => 'Customs']);
    Queue::fake();

    $this->project->update(['title' => 'Tax Portal 2']);
    Queue::assertNotPushed(SyncRepositoryAccess::class);

    $this->project->update(['program_id' => $customs->id]);
    expect(trigSorted(trigSynced()))->toBe(['sdoc/tp-api', 'sdoc/tp-web']);

    Queue::fake();
    $this->project->update(['program_id' => null]);
    expect(trigSorted(trigSynced()))->toBe(['sdoc/tp-api', 'sdoc/tp-web']);
});

// Linking

/** @return array<string, mixed> api.Repository, the fields the tracker reads */
function trigForgeRepository(): array
{
    return [
        'id' => 601,
        'owner' => ['id' => 9, 'login' => 'sdoc', 'full_name' => 'SDOC', 'username' => 'sdoc'],
        'name' => 'portal',
        'full_name' => 'sdoc/portal',
        'description' => 'The portal code',
        'empty' => false,
        'private' => true,
        'fork' => false,
        'mirror' => false,
        'html_url' => 'https://git.example/sdoc/portal',
        'url' => 'https://git.example/api/v1/repos/sdoc/portal',
        'ssh_url' => 'git@git.example:sdoc/portal.git',
        'clone_url' => 'https://git.example/sdoc/portal.git',
        'default_branch' => 'main',
        'archived' => false,
        'has_issues' => true,
        'has_pull_requests' => true,
        'permissions' => ['admin' => true, 'push' => true, 'pull' => true],
    ];
}

/**
 * sdoc/portal on the forge; its collaborators are listed by $collaborators
 * and their permission answered by $permission.
 */
function trigFakeLink(Closure $collaborators, ?Closure $permission = null): void
{
    Http::preventStrayRequests();
    Http::fake([
        'git.example/api/v1/repos/sdoc/portal/collaborators/*/permission' => $permission ?? fn () => Http::response(['permission' => 'write', 'role_name' => 'write', 'user' => ['id' => 1, 'login' => 'asha']]),
        'git.example/api/v1/repos/sdoc/portal/collaborators?*' => $collaborators,
        'git.example/api/v1/repos/sdoc/portal' => Http::response(trigForgeRepository()),
    ]);
}

it('linking imports the forge\'s collaborators and queues a sync', function () {
    trigFakeLink(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(($query['page'] ?? '1') === '1'
            ? [['id' => 1, 'login' => 'asha', 'username' => 'asha'], ['id' => 2, 'login' => 'contractor', 'username' => 'contractor']]
            : []);
    });

    $this->actingAs($this->adminUser)
        ->post("/workspace/projects/{$this->project->slug}/repositories", ['full_name' => 'sdoc/portal'])
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Linked sdoc/portal.']);

    $repo = GitRepository::query()->where('full_name', 'sdoc/portal')->sole();
    $grant = $repo->grants()->sole();
    expect([$grant->member_id, $grant->permission, $grant->granted_by])->toBe([$this->asha->id, 'write', $this->adminUser->id])
        ->and(array_column(trigGrantActivity($this->project), 'description'))->toBe(['gave Asha write access to sdoc/portal'])
        ->and(trigSynced())->toContain('sdoc/portal')
        ->and(array_values(array_unique(trigSynced())))->toBe(['sdoc/portal']);
    Queue::assertPushed(BackfillGitRepository::class, fn (BackfillGitRepository $job) => $job->repository->is($repo));
});

it('links and still queues a sync when the forge fails on the import, logging why without the token', function (Closure $collaborators, int $status) {
    Log::spy();
    trigFakeLink($collaborators);

    $this->actingAs($this->adminUser)
        ->post("/workspace/projects/{$this->project->slug}/repositories", ['full_name' => 'sdoc/portal'])
        ->assertSessionHas('workspace_flash', ['success' => true, 'message' => 'Linked sdoc/portal.']);

    expect(GitRepository::query()->where('full_name', 'sdoc/portal')->sole()->grants()->count())->toBe(0)
        ->and(trigSynced())->toBe(['sdoc/portal']);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === "Couldn't import a forge repository's collaborators."
        && $context['owner'] === 'sdoc'
        && $context['repository'] === 'portal'
        && $context['status'] === $status
        && ($status !== 0 || ($context['url'] === 'https://git.example' && str_contains($context['cause'], '[token]')))
        && ! str_contains((string) json_encode($context), 'never-shown'))->once();
})->with([
    'refused' => [fn () => fn () => Http::response(['message' => 'boom'], 500), 500],
    'down' => [fn () => fn () => throw new ConnectionException('cURL error 7: Failed to connect bot-token-never-shown'), 0],
]);
