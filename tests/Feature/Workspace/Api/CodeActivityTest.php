<?php

use App\Models\GitCommit;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
 * The JSON twin of CodeActivityTest: the main cases through Sanctum. SDOC
 * has Tax Portal and HR System, MoF has Budget; all three have a repository.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    CarbonImmutable::setTestNow('2026-10-28 10:00:00');

    $this->sdoc = Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
    $this->mof = Organization::factory()->create(['name' => 'MoF', 'slug' => 'mof']);

    $this->tax = Project::factory()->create(['organization_id' => $this->sdoc->id, 'title' => 'Tax Portal', 'slug' => 'tax-portal', 'key' => 'TP']);
    $this->hr = Project::factory()->create(['organization_id' => $this->sdoc->id, 'title' => 'HR System', 'slug' => 'hr-system', 'key' => 'HR']);
    $this->budget = Project::factory()->create(['organization_id' => $this->mof->id, 'title' => 'Budget', 'slug' => 'budget', 'key' => 'BU']);

    $taxRepo = apiCodeActivityRepository($this->tax, 'sdoc', 'tp-api', 1);
    $hrRepo = apiCodeActivityRepository($this->hr, 'sdoc', 'hr-api', 2);
    $budgetRepo = apiCodeActivityRepository($this->budget, 'mof', 'bu-api', 3);

    $this->boss = User::factory()->create(['email' => 'boss@example.com', 'name' => 'Boss']);
    $this->sdocAdmin = apiCodeActivityPerson($this->sdoc, 'Sita Admin', officeAdmin: true);
    $this->hrManager = apiCodeActivityPerson($this->sdoc, 'Hari Manager', team: $this->hr, role: 'leader');
    $this->asha = apiCodeActivityPerson($this->sdoc, 'Asha Dev', team: $this->tax);
    $this->bikash = apiCodeActivityPerson($this->sdoc, 'Bikash Dev', team: $this->hr);
    $this->chandra = apiCodeActivityPerson($this->mof, 'Chandra Dev', team: $this->budget);

    apiCodeActivityCommit($taxRepo, $this->asha, 'a1', '2026-10-20 09:00:00', 10, "TP-1 First\n\nbody");
    apiCodeActivityCommit($taxRepo, $this->asha, 'a2', '2026-10-21 09:00:00', 5, 'TP-1 Second');
    apiCodeActivityCommit($hrRepo, $this->bikash, 'b1', '2026-10-22 09:00:00', 3);
    apiCodeActivityCommit($budgetRepo, $this->chandra, 'c1', '2026-10-23 09:00:00', 7);
    apiCodeActivityCommit($taxRepo, null, 'u1', '2026-10-24 09:00:00', 4);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function apiCodeActivityRepository(Project $project, string $owner, string $name, int $forgeId): GitRepository
{
    $connection = GitConnection::query()->firstOrCreate(['organization_id' => $project->organization_id], [
        'base_url' => 'https://git.example',
        'owner' => $owner,
        'token' => 'bot-token-never-shown',
        'webhook_id' => 99,
        'webhook_secret' => 'hook-secret-never-shown',
    ]);

    return GitRepository::query()->create([
        'git_connection_id' => $connection->id,
        'project_id' => $project->id,
        'forge_id' => $forgeId,
        'owner' => $owner,
        'name' => $name,
        'full_name' => "{$owner}/{$name}",
        'html_url' => "https://git.example/{$owner}/{$name}",
        'clone_url' => "https://git.example/{$owner}/{$name}.git",
        'ssh_url' => "git@git.example:{$owner}/{$name}.git",
        'default_branch' => 'main',
    ]);
}

function apiCodeActivityPerson(Organization $organization, string $name, bool $officeAdmin = false, ?Project $team = null, string $role = 'member'): User
{
    $user = User::factory()->create(['name' => $name]);
    $member = Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id, 'is_office_admin' => $officeAdmin]);
    $team?->ensureTeam()->members()->attach($member->id, ['role' => $role]);

    return $user;
}

function apiCodeActivityCommit(GitRepository $repo, ?User $author, string $sha, string $committedAt, int $codeAdded, string $message = 'Change things'): GitCommit
{
    $commit = new GitCommit;
    $commit->forceFill([
        'git_repository_id' => $repo->id,
        'user_id' => $author?->id,
        'sha' => $sha,
        'message' => $message,
        'author_name' => $author->name ?? 'Stranger',
        'author_username' => null,
        'url' => "{$repo->html_url}/commit/{$sha}",
        'committed_at' => $committedAt,
        'code_added' => $codeAdded,
        'stats_status' => 'ok',
        'stats_computed_at' => '2026-10-28 09:00:00',
    ])->save();

    return $commit;
}

it('reports a super-admin every organization and an organizational admin only theirs', function () {
    Sanctum::actingAs($this->boss, ['*']);
    $boss = $this->getJson('/api/v1/workspace/reports/code')->assertOk();

    Sanctum::actingAs($this->sdocAdmin, ['*']);
    $admin = $this->getJson('/api/v1/workspace/reports/code')->assertOk();

    expect(collect($boss->json('data.people'))->pluck('name')->all())->toBe(['Asha Dev', 'Chandra Dev', 'Not matched to anyone', 'Bikash Dev'])
        ->and(collect($admin->json('data.people'))->pluck('name')->all())->toBe(['Asha Dev', 'Not matched to anyone', 'Bikash Dev'])
        ->and($admin->json('data.people.0.code_added'))->toBe(15)
        ->and($admin->json('data.range'))->toBe(['from' => '2026-09-29', 'to' => '2026-10-28'])
        ->and($admin->json('data.uncounted'))->toBe(0)
        ->and($admin->json('data.options.projects'))->toEqual([['slug' => 'hr-system', 'title' => 'HR System', 'label' => 'HR System'], ['slug' => 'tax-portal', 'title' => 'Tax Portal', 'label' => 'Tax Portal']])
        ->and($admin->json('filters'))->toEqual(['from' => '2026-09-29', 'to' => '2026-10-28', 'project' => null, 'program' => null, 'members' => []]);
});

it('reports a project manager their projects and a member only themselves', function () {
    Sanctum::actingAs($this->hrManager, ['*']);
    $manager = $this->getJson('/api/v1/workspace/reports/code')->assertOk();

    Sanctum::actingAs($this->asha, ['*']);
    $member = $this->getJson('/api/v1/workspace/reports/code')->assertOk();

    expect(collect($manager->json('data.people'))->pluck('name')->all())->toBe(['Bikash Dev'])
        ->and(collect($member->json('data.people'))->pluck('name')->all())->toBe(['Asha Dev']);
});

it('refuses a filter outside the scope and anyone without git', function () {
    Sanctum::actingAs($this->hrManager, ['*']);
    $this->getJson('/api/v1/workspace/reports/code?project=tax-portal')->assertForbidden();
    $this->getJson('/api/v1/workspace/reports/code?project=hr-system')->assertOk();

    $outsider = apiCodeActivityPerson(Organization::factory()->create(['name' => 'No git']), 'Out Sider');
    Sanctum::actingAs($outsider, ['*']);
    $this->getJson('/api/v1/workspace/reports/code')->assertForbidden();
    $this->getJson("/api/v1/workspace/reports/code/people/{$this->asha->id}")->assertForbidden();
});

it('validates the people compared and the range', function () {
    Sanctum::actingAs($this->sdocAdmin, ['*']);

    $five = collect(range(1, 5))->map(fn (int $id) => "members[]={$id}")->implode('&');
    $this->getJson("/api/v1/workspace/reports/code?{$five}")->assertUnprocessable()->assertJsonValidationErrors('members');
    $this->getJson('/api/v1/workspace/reports/code?from=2025-10-29&to=2026-10-30')->assertUnprocessable()->assertJsonValidationErrors('to');

    $two = $this->getJson("/api/v1/workspace/reports/code?members[]={$this->asha->id}&members[]={$this->bikash->id}")->assertOk();

    expect($two->json('data.series'))->toHaveCount(2)
        ->and($two->json('data.series.0.days'))->toHaveCount(30);
});

it("lists a person's commits in the scope, and nothing for someone outside it", function () {
    Sanctum::actingAs($this->sdocAdmin, ['*']);
    $asha = $this->getJson("/api/v1/workspace/reports/code/people/{$this->asha->id}")->assertOk();

    expect(collect($asha->json('data'))->pluck('sha')->all())->toBe(['a2', 'a1'])
        ->and($asha->json('data.1.message'))->toBe('TP-1 First')
        ->and($asha->json('data.1.repository'))->toBe('sdoc/tp-api');

    $this->getJson("/api/v1/workspace/reports/code/people/{$this->chandra->id}")->assertOk()->assertExactJson(['data' => []]);

    Sanctum::actingAs($this->hrManager, ['*']);
    $this->getJson("/api/v1/workspace/reports/code/people/{$this->asha->id}")->assertOk()->assertExactJson(['data' => []]);
});
