<?php

use App\Jobs\BackfillGitRepository;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * The JSON twin of UnlinkedRepositoriesTest: Code activity's `unlinked` and
 * "New project for it" through Sanctum. SDOC's forge organization holds
 * sdoc/ird-prize and sdoc/sdoc, neither linked.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com'], 'app.url' => 'https://tasks.example.test']);

    $this->sdoc = Organization::factory()->create(['name' => 'SDOC']);
    $this->connection = GitConnection::query()->create([
        'organization_id' => $this->sdoc->id,
        'base_url' => 'https://git.example',
        'owner' => 'sdoc',
        'token' => 'bot-token-never-shown',
        'webhook_id' => 99,
        'webhook_secret' => 'hook-secret-never-shown',
    ]);
    $this->tax = Project::factory()->create(['organization_id' => $this->sdoc->id, 'title' => 'Tax Portal', 'key' => 'TP']);
    $this->admin = apiUnlinkedPerson($this->sdoc, officeAdmin: true);
    $this->manager = apiUnlinkedPerson($this->sdoc, team: $this->tax);
    $this->url = "/api/v1/workspace/git/connections/{$this->connection->id}/repositories/502/project";

    $prize = apiUnlinkedForgeRepository(502, 'ird-prize');
    $list = [$prize, apiUnlinkedForgeRepository(503, 'sdoc')];
    Http::fake([
        'git.example/api/v1/orgs/sdoc/repos*' => function (Request $request) use ($list) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(($query['page'] ?? '1') === '1' ? $list : []);
        },
        'git.example/api/v1/repositories/502' => Http::response($prize),
        'git.example/api/v1/repos/sdoc/ird-prize' => Http::response($prize),
        'git.example/api/v1/repos/sdoc/ird-prize/collaborators*' => Http::response([]),
    ]);
});

function apiUnlinkedPerson(Organization $organization, bool $officeAdmin = false, ?Project $team = null): User
{
    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id, 'is_office_admin' => $officeAdmin]);
    $team?->ensureTeam()->members()->attach($member->id, ['role' => 'leader']);

    return $user;
}

/** @return array<string, mixed> api.Repository */
function apiUnlinkedForgeRepository(int $id, string $name): array
{
    return [
        'id' => $id,
        'owner' => ['id' => 9, 'login' => 'sdoc', 'username' => 'sdoc'],
        'name' => $name,
        'full_name' => "sdoc/{$name}",
        'description' => '',
        'html_url' => "https://git.example/sdoc/{$name}",
        'ssh_url' => "git@git.example:sdoc/{$name}.git",
        'clone_url' => "https://git.example/sdoc/{$name}.git",
        'default_branch' => 'main',
        'archived' => false,
        'updated_at' => sprintf('2026-11-%02dT10:00:00+05:45', $id - 500),
    ];
}

it("gives the organization's administrators the unlinked repositories with the code activity report", function () {
    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/v1/workspace/reports/code')
        ->assertOk()
        ->assertJsonPath('unlinked.0.connection', ['id' => $this->connection->id, 'organization' => ['id' => $this->sdoc->id, 'name' => 'SDOC']])
        ->assertJsonPath('unlinked.0.unavailable', false)
        ->assertJsonPath('unlinked.0.repositories.*.full_name', ['sdoc/sdoc', 'sdoc/ird-prize'])
        ->assertJsonPath('unlinked.0.repositories.1.suggested_title', 'IRD Prize')
        ->assertJsonPath('unlinked.0.repositories.1.suggested_key', 'IP')
        ->assertJsonPath('unlinked.0.projects.*.slug', [$this->tax->slug])
        ->assertJsonPath('data.repositories', 0);
});

it('gives everyone else an empty list, without asking the forge', function () {
    $this->actingAs($this->manager, 'sanctum')
        ->getJson('/api/v1/workspace/reports/code')
        ->assertOk()
        ->assertJsonPath('unlinked', []);

    Http::assertNothingSent();
});

it('creates the project and links the repository, answering 201 with both', function () {
    Queue::fake();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson($this->url, ['title' => 'IRD Prize'])
        ->assertCreated()
        ->assertJsonPath('message', 'Created IRD Prize and linked sdoc/ird-prize. Its earlier commits are being read now.')
        ->assertJsonPath('data.project.title', 'IRD Prize')
        ->assertJsonPath('data.project.key', 'IP')
        ->assertJsonPath('data.repository.full_name', 'sdoc/ird-prize');

    $project = Project::query()->where('title', 'IRD Prize')->sole();
    expect($project->manager()?->id)->toBe(WorkspaceAccess::memberFor($this->admin)?->id)
        ->and(GitRepository::query()->sole()->project_id)->toBe($project->id);
    Queue::assertPushed(BackfillGitRepository::class);
});

it('answers 422 for a repository already linked, and 403 to a project manager', function () {
    Queue::fake();
    $this->tax->repositories()->create([
        'git_connection_id' => $this->connection->id,
        'forge_id' => 502,
        'owner' => 'sdoc',
        'name' => 'ird-prize',
        'full_name' => 'sdoc/ird-prize',
        'html_url' => 'https://git.example/sdoc/ird-prize',
        'clone_url' => 'https://git.example/sdoc/ird-prize.git',
        'ssh_url' => 'git@git.example:sdoc/ird-prize.git',
        'default_branch' => 'main',
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson($this->url, ['title' => 'IRD Prize'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'That repository is no longer waiting to be linked.');

    $this->actingAs($this->manager, 'sanctum')
        ->postJson($this->url, ['title' => 'IRD Prize'])
        ->assertForbidden();

    expect(Project::query()->where('title', 'IRD Prize')->exists())->toBeFalse();
});
