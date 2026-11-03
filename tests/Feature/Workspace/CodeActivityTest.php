<?php

use App\Jobs\SyncRepositoryAccess;
use App\Models\Designation;
use App\Models\GitCommit;
use App\Models\GitConnection;
use App\Models\GitPullRequest;
use App\Models\GitRepository;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Git\Checks;
use App\Support\Git\GitAccess;
use App\Support\WorkspaceAccess;
use App\Support\WorkspaceOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
 * The code activity report (/workspace/reports/code): who committed what,
 * scoped to what each viewer runs. Two organizations with git: SDOC (a
 * Revenue program holding Tax Portal, and HR System outside any program)
 * and MoF (Budget). Today is Wednesday 2026-10-28: the default period, this
 * week, runs from Sunday 2026-10-25, so most tests ask for `period=custom`,
 * whose default range is the last 30 days (2026-09-29 to 2026-10-28).
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    CarbonImmutable::setTestNow('2026-10-28 10:00:00');
    // No forge here: joining a team with repositories queues their access
    // sync, and an administrator's report lists the forge's unlinked
    // repositories (none).
    Bus::fake([SyncRepositoryAccess::class]);
    Http::fake(['git.example/api/v1/orgs/*/repos?*' => Http::response([])]);

    $this->sdoc = Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
    $this->mof = Organization::factory()->create(['name' => 'MoF', 'slug' => 'mof']);
    $sdocGit = codeActivityGit($this->sdoc, 'sdoc');
    $mofGit = codeActivityGit($this->mof, 'mof');

    $this->boss = User::factory()->create(['email' => 'boss@example.com', 'name' => 'Boss']);
    $this->sdocAdmin = codeActivityPerson($this->sdoc, 'Sita Admin', officeAdmin: true);
    $this->mofAdmin = codeActivityPerson($this->mof, 'Mohan Admin', officeAdmin: true);
    $this->programManager = codeActivityPerson($this->sdoc, 'Ram Joint');

    $this->program = Program::factory()->create([
        'organization_id' => $this->sdoc->id,
        'name' => 'Revenue',
        'manager_id' => WorkspaceAccess::memberFor($this->programManager)?->id,
    ]);

    $this->tax = Project::factory()->create(['organization_id' => $this->sdoc->id, 'program_id' => $this->program->id, 'title' => 'Tax Portal', 'slug' => 'tax-portal', 'key' => 'TP']);
    $this->hr = Project::factory()->create(['organization_id' => $this->sdoc->id, 'title' => 'HR System', 'slug' => 'hr-system', 'key' => 'HR']);
    $this->budget = Project::factory()->create(['organization_id' => $this->mof->id, 'title' => 'Budget', 'slug' => 'budget', 'key' => 'BU']);

    $this->taxRepo = codeActivityRepository($sdocGit, $this->tax, 'tp-api', 1);
    $this->hrRepo = codeActivityRepository($sdocGit, $this->hr, 'hr-api', 2);
    $this->budgetRepo = codeActivityRepository($mofGit, $this->budget, 'bu-api', 3);

    $this->taxManager = codeActivityPerson($this->sdoc, 'Tara Manager', team: $this->tax, role: 'leader');
    $this->hrManager = codeActivityPerson($this->sdoc, 'Hari Manager', team: $this->hr, role: 'leader');
    $this->asha = codeActivityPerson($this->sdoc, 'Asha Dev', team: $this->tax, designation: 'Senior developer', gitUsername: 'asha');
    $this->bikash = codeActivityPerson($this->sdoc, 'Bikash Dev', team: $this->hr, gitUsername: 'bikash');
    $this->chandra = codeActivityPerson($this->mof, 'Chandra Dev', team: $this->budget, gitUsername: 'chandra');

    codeActivityCommit($this->taxRepo, $this->asha, 'a1', '2026-10-20 09:00:00', ['code_added' => 10, 'code_removed' => 2, 'comment_added' => 3, 'comment_removed' => 1, 'blank_added' => 2, 'files_changed' => 2]);
    codeActivityCommit($this->taxRepo, $this->asha, 'a2', '2026-10-21 15:00:00', ['code_added' => 5, 'code_removed' => 1, 'blank_added' => 1, 'blank_removed' => 1, 'docs_added' => 4, 'files_changed' => 3], "TP-1 Add the login form\n\nA long body that stays on the forge.");
    codeActivityCommit($this->hrRepo, $this->bikash, 'b1', '2026-10-22 11:00:00', ['code_added' => 3]);
    codeActivityCommit($this->budgetRepo, $this->chandra, 'c1', '2026-10-23 11:00:00', ['code_added' => 7, 'code_removed' => 7]);
    codeActivityCommit($this->taxRepo, null, 'u1', '2026-10-24 11:00:00', ['code_added' => 4, 'code_removed' => 4]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function codeActivityGit(Organization $organization, string $owner): GitConnection
{
    return GitConnection::query()->create([
        'organization_id' => $organization->id,
        'base_url' => 'https://git.example',
        'owner' => $owner,
        'token' => 'bot-token-never-shown',
        'webhook_id' => 99,
        'webhook_secret' => 'hook-secret-never-shown',
    ]);
}

function codeActivityRepository(GitConnection $connection, Project $project, string $name, int $forgeId): GitRepository
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

function codeActivityPerson(Organization $organization, string $name, bool $officeAdmin = false, ?Project $team = null, string $role = 'member', ?string $designation = null, ?string $gitUsername = null): User
{
    $user = User::factory()->create(['name' => $name, 'git_username' => $gitUsername]);
    $member = Member::factory()->linkedTo($user)
        ->when($designation !== null, fn ($factory) => $factory->designated((string) $designation))
        ->create([
            'organization_id' => $organization->id,
            'is_office_admin' => $officeAdmin,
        ]);

    $team?->ensureTeam()->members()->attach($member->id, ['role' => $role]);

    return $user;
}

/** @param  array<string, mixed>  $counts */
function codeActivityCommit(GitRepository $repo, ?User $author, string $sha, string $committedAt, array $counts = [], string $message = 'Change things'): GitCommit
{
    $commit = new GitCommit;
    $commit->forceFill($counts + [
        'git_repository_id' => $repo->id,
        'user_id' => $author?->id,
        'sha' => $sha,
        'message' => $message,
        'author_name' => $author->name ?? 'Stranger',
        'author_username' => $author->git_username ?? 'stranger',
        'url' => "{$repo->html_url}/commit/{$sha}",
        'committed_at' => $committedAt,
        'files_changed' => 1,
        'stats_status' => 'ok',
        'stats_computed_at' => '2026-10-28 09:00:00',
    ])->save();

    return $commit;
}

/** @return array<string, mixed> api.Repository as the forge answers for one it has just made */
function codeActivityForgeRepository(string $name, int $id): array
{
    return [
        'id' => $id,
        'owner' => ['id' => 9, 'login' => 'sdoc', 'full_name' => 'SDOC', 'username' => 'sdoc'],
        'name' => $name,
        'full_name' => "sdoc/{$name}",
        'description' => '',
        'empty' => false,
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
        'permissions' => ['admin' => true, 'push' => true, 'pull' => true],
    ];
}

/** @param  array<string, mixed>  $attributes */
function codeActivityPullRequest(GitRepository $repo, int $number, ?string $author, string $openedAt, ?string $mergedAt = null, array $attributes = []): GitPullRequest
{
    return GitPullRequest::query()->create($attributes + [
        'git_repository_id' => $repo->id,
        'number' => $number,
        'title' => "Change {$number}",
        'state' => $mergedAt === null ? GitPullRequest::STATE_OPEN : GitPullRequest::STATE_MERGED,
        'url' => "{$repo->html_url}/pulls/{$number}",
        'head_branch' => "change-{$number}",
        'author_username' => $author,
        'opened_at' => $openedAt,
        'merged_at' => $mergedAt,
        'closed_at' => $mergedAt,
    ]);
}

/** @return array<string, mixed> */
function codeActivityReport(TestResponse $response): array
{
    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/CodeActivity'));

    return $response->viewData('page')['props'];
}

/**
 * @param  array<string, mixed>  $report
 * @return array<string, array<string, mixed>> people by name
 */
function codeActivityPeople(array $report): array
{
    return collect($report['people'])->keyBy('name')->all();
}

// ---- Who sees what ----

it('shows a super-admin the people of every organization, by name, with the commits no one is matched to last', function () {
    $report = codeActivityReport($this->actingAs($this->boss)->get('/workspace/reports/code?period=custom'));

    expect(collect($report['people'])->pluck('name')->all())->toBe(['Asha Dev', 'Bikash Dev', 'Chandra Dev', 'Not matched to anyone']);
});

it('lists people by name, in any case and unranked, when sorted by name', function () {
    $bina = codeActivityPerson($this->sdoc, 'bina Dev', team: $this->hr, gitUsername: 'bina');
    codeActivityCommit($this->hrRepo, $bina, 'n1', '2026-10-25 10:00:00', ['code_added' => 5000]);
    codeActivityCommit($this->hrRepo, null, 'u9', '2026-10-25 11:00:00', ['code_added' => 9000]);

    $report = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom&sort=name'));

    expect(collect($report['people'])->pluck('name')->all())->toBe(['Asha Dev', 'Bikash Dev', 'bina Dev', 'Not matched to anyone'])
        ->and(collect($report['people'])->pluck('rank')->unique()->all())->toBe([null])
        ->and($report['filters']['sort'])->toBe('name');
});

it('shows a super-admin switched to one organization only its people and repositories, as its admins see them', function () {
    WorkspaceOrganization::choose($this->boss, $this->mof->id);

    $report = codeActivityReport($this->actingAs($this->boss)->get('/workspace/reports/code?period=custom'));

    expect(collect($report['people'])->pluck('name')->all())->toBe(['Chandra Dev'])
        ->and($report['repositories'])->toBe(1)
        ->and($this->actingAs($this->boss)->getJson("/workspace/reports/code/people/{$this->asha->id}")->json('data'))->toBe([]);

    // The app has no switch: it keeps every organization.
    expect(collect($this->actingAs($this->boss, 'sanctum')->getJson('/api/v1/workspace/reports/code?period=custom')->json('data.people'))->pluck('name')->all())
        ->toBe(['Asha Dev', 'Bikash Dev', 'Chandra Dev', 'Not matched to anyone']);
});

it('shows an organizational admin only their organization, with the commits no one is matched to', function () {
    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom')));

    expect(array_keys($people))->toBe(['Asha Dev', 'Bikash Dev', 'Not matched to anyone'])
        ->and($people['Not matched to anyone']['user_id'])->toBeNull()
        ->and($people['Not matched to anyone']['commits'])->toBe(1)
        ->and($people['Asha Dev'])->toMatchArray([
            'user_id' => $this->asha->id,
            'designation' => 'Senior developer',
            'commits' => 2,
            'active_days' => 2,
            'repositories' => 1,
            'files_changed' => 5,
            'code_added' => 15,
            'code_removed' => 3,
            'comment_added' => 3,
            'comment_removed' => 1,
            'blank_added' => 3,
            'blank_removed' => 1,
            'docs_added' => 4,
            'docs_removed' => 0,
            'pull_requests_opened' => 0,
            'pull_requests_merged' => 0,
            'tasks' => 0,
        ]);
});

it('counts the linked repositories whose code the viewer may see, so an empty report can say none are linked', function () {
    expect(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom'))['repositories'])->toBe(2)
        ->and(codeActivityReport($this->actingAs($this->mofAdmin)->get('/workspace/reports/code?period=custom'))['repositories'])->toBe(1);

    GitRepository::query()->delete();

    expect(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom'))['repositories'])->toBe(0);
});

it('shows an organizational admin of another organization nobody of this one', function () {
    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->mofAdmin)->get('/workspace/reports/code?period=custom')));

    expect(array_keys($people))->toBe(['Chandra Dev']);
});

it("shows a program manager only their programs' projects", function () {
    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->programManager)->get('/workspace/reports/code?period=custom')));

    expect(array_keys($people))->toBe(['Asha Dev', 'Not matched to anyone']);
});

it('shows a project manager only the projects they manage', function () {
    $tax = codeActivityPeople(codeActivityReport($this->actingAs($this->taxManager)->get('/workspace/reports/code?period=custom')));
    $hr = codeActivityPeople(codeActivityReport($this->actingAs($this->hrManager)->get('/workspace/reports/code?period=custom')));

    expect(array_keys($tax))->toBe(['Asha Dev', 'Not matched to anyone'])
        ->and(array_keys($hr))->toBe(['Bikash Dev']);
});

it('shows a member only their own row, never the commits no one is matched to', function () {
    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->asha)->get('/workspace/reports/code?period=custom')));

    expect(array_keys($people))->toBe(['Asha Dev'])
        ->and($people['Asha Dev']['commits'])->toBe(2);
});

it("counts a person's own commits wherever they are, and each repository's owners see only theirs", function () {
    codeActivityCommit($this->budgetRepo, $this->asha, 'a3', '2026-10-22 10:00:00', ['code_added' => 100]);

    $own = codeActivityPeople(codeActivityReport($this->actingAs($this->asha)->get('/workspace/reports/code?period=custom')));
    $sdoc = codeActivityPeople(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom')));
    $mof = codeActivityPeople(codeActivityReport($this->actingAs($this->mofAdmin)->get('/workspace/reports/code?period=custom')));

    expect($own['Asha Dev']['commits'])->toBe(3)
        ->and($own['Asha Dev']['repositories'])->toBe(2)
        ->and($sdoc['Asha Dev']['commits'])->toBe(2)
        ->and($mof['Asha Dev']['commits'])->toBe(1)
        ->and($mof['Asha Dev']['code_added'])->toBe(100);
});

it('never reaches a project of another organization through a program id it carries', function () {
    $stray = Project::factory()->create(['organization_id' => $this->mof->id, 'program_id' => $this->program->id, 'title' => 'Stray']);
    $strayRepo = codeActivityRepository(GitConnection::query()->where('owner', 'mof')->firstOrFail(), $stray, 'stray', 4);
    codeActivityCommit($strayRepo, $this->chandra, 's1', '2026-10-22 10:00:00', ['code_added' => 9]);

    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->programManager)->get('/workspace/reports/code?period=custom')));

    expect($people)->not->toHaveKey('Chandra Dev');
});

it('gives a deactivated project manager no projects in the report scope', function () {
    WorkspaceAccess::memberFor($this->taxManager)?->update(['is_active' => false]);
    WorkspaceAccess::flush();

    expect(WorkspaceAccess::codeReportScope($this->taxManager))->toBe([
        'all' => false,
        'organization_ids' => [],
        'program_ids' => [],
        'project_ids' => [],
        'user_id' => $this->taxManager->id,
    ]);
});

it('builds each role its scope', function () {
    expect(WorkspaceAccess::codeReportScope($this->boss)['all'])->toBeTrue()
        ->and(WorkspaceAccess::codeReportScope($this->sdocAdmin)['organization_ids'])->toBe([$this->sdoc->id])
        ->and(WorkspaceAccess::codeReportScope($this->programManager)['program_ids'])->toBe([$this->program->id])
        ->and(WorkspaceAccess::codeReportScope($this->taxManager)['project_ids'])->toBe([$this->tax->id])
        ->and(WorkspaceAccess::codeReportScope($this->asha))->toBe([
            'all' => false,
            'organization_ids' => [],
            'program_ids' => [],
            'project_ids' => [],
            'user_id' => $this->asha->id,
        ])
        ->and(WorkspaceAccess::codeReportScope(null)['user_id'])->toBeNull();
});

it('refuses anyone the tracker does not admit to git', function () {
    $elsewhere = Organization::factory()->create(['name' => 'No git']);
    $outsider = codeActivityPerson($elsewhere, 'Out Sider');
    $deactivated = codeActivityPerson($this->sdoc, 'Gone Dev');
    WorkspaceAccess::memberFor($deactivated)?->update(['is_active' => false]);
    WorkspaceAccess::flush();

    $this->actingAs($outsider)->get('/workspace/reports/code')->assertForbidden();
    $this->actingAs($outsider)->getJson("/workspace/reports/code/people/{$this->asha->id}")->assertForbidden();
    // A deactivated person is signed out before the report's own check.
    expect(GitAccess::anyFor($deactivated))->toBeFalse();
    $this->actingAs($deactivated)->get('/workspace/reports/code')->assertRedirect('/workspace/login');
});

// ---- Counting ----

it('leaves out merges and commits no branch holds any more', function () {
    codeActivityCommit($this->taxRepo, $this->asha, 'm1', '2026-10-25 10:00:00', ['is_merge' => true, 'stats_status' => 'merge', 'code_added' => 50]);
    codeActivityCommit($this->taxRepo, $this->asha, 'r1', '2026-10-25 11:00:00', ['unreachable_at' => '2026-10-26 10:00:00', 'code_added' => 60]);

    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom')));

    expect($people['Asha Dev']['commits'])->toBe(2)
        ->and($people['Asha Dev']['code_added'])->toBe(15);
});

it('counts only commits inside the range, both ends whole days', function () {
    codeActivityCommit($this->taxRepo, $this->asha, 'early', '2026-10-19 23:59:59', ['code_added' => 1000]);
    codeActivityCommit($this->taxRepo, $this->asha, 'old', '2026-09-28 23:59:59', ['code_added' => 2000]);

    $default = codeActivityPeople(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom')));
    $oneDay = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom&from=2026-10-20&to=2026-10-20'));

    expect($default['Asha Dev']['commits'])->toBe(3)
        ->and($default['Asha Dev']['code_added'])->toBe(1015)
        ->and($oneDay['range'])->toBe(['from' => '2026-10-20', 'to' => '2026-10-20'])
        ->and(collect($oneDay['people'])->pluck('name')->all())->toBe(['Asha Dev'])
        ->and($oneDay['people'][0]['code_added'])->toBe(10);
});

it('echoes the range and filters it applied, a custom period defaulting to the last 30 days', function () {
    $report = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom'));

    expect($report['range'])->toBe(['from' => '2026-09-29', 'to' => '2026-10-28'])
        ->and($report['filters'])->toEqual(['period' => 'custom', 'from' => '2026-09-29', 'to' => '2026-10-28', 'project' => null, 'program' => null, 'designation' => null, 'sort' => 'commits', 'members' => []]);
});

it('opens on this week, by commits', function () {
    $report = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code'));

    expect($report['range'])->toBe(['from' => '2026-10-25', 'to' => '2026-10-28'])
        ->and($report['filters'])->toEqual(['period' => 'week', 'from' => '2026-10-25', 'to' => '2026-10-28', 'project' => null, 'program' => null, 'designation' => null, 'sort' => 'commits', 'members' => []])
        ->and($report['options']['periods'])->toBe([
            ['value' => 'today', 'label' => 'Today'],
            ['value' => 'week', 'label' => 'This week'],
            ['value' => 'last_week', 'label' => 'Last week'],
            ['value' => 'month', 'label' => 'This month'],
            ['value' => 'custom', 'label' => 'Custom'],
        ]);
});

it('narrows to a project or a program inside the scope', function () {
    $hr = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom&project=hr-system'));
    $revenue = codeActivityReport($this->actingAs($this->sdocAdmin)->get("/workspace/reports/code?period=custom&program={$this->program->slug}"));

    expect(collect($hr['people'])->pluck('name')->all())->toBe(['Bikash Dev'])
        ->and($hr['filters']['project'])->toBe('hr-system')
        ->and(collect($revenue['people'])->pluck('name')->all())->toBe(['Asha Dev', 'Not matched to anyone'])
        ->and($revenue['filters']['program'])->toBe($this->program->slug);
});

it('refuses a project or program filter outside the scope, or one that does not exist', function () {
    $this->actingAs($this->hrManager)->get('/workspace/reports/code?project=tax-portal')->assertForbidden();
    $this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?project=budget')->assertForbidden();
    $this->actingAs($this->taxManager)->get("/workspace/reports/code?program={$this->program->slug}")->assertForbidden();
    $this->actingAs($this->asha)->get('/workspace/reports/code?project=tax-portal')->assertForbidden();
    $this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?project=no-such-project')->assertForbidden();
    $this->actingAs($this->asha)->getJson("/workspace/reports/code/people/{$this->asha->id}?project=hr-system")->assertForbidden();
});

it('counts pull requests opened and merged in the range, by author in any case', function () {
    codeActivityPullRequest($this->taxRepo, 1, 'Asha', '2026-10-10 10:00:00', '2026-10-12 10:00:00');
    codeActivityPullRequest($this->taxRepo, 2, 'asha', '2026-09-01 10:00:00', '2026-10-15 10:00:00');
    codeActivityPullRequest($this->taxRepo, 3, 'asha', '2026-10-25 10:00:00');
    codeActivityPullRequest($this->taxRepo, 4, 'asha', '2026-08-01 10:00:00', '2026-08-02 10:00:00');
    codeActivityPullRequest($this->budgetRepo, 1, 'asha', '2026-10-25 10:00:00');

    $sdoc = codeActivityPeople(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom')));
    $own = codeActivityPeople(codeActivityReport($this->actingAs($this->asha)->get('/workspace/reports/code?period=custom')));

    expect($sdoc['Asha Dev']['pull_requests_opened'])->toBe(2)
        ->and($sdoc['Asha Dev']['pull_requests_merged'])->toBe(2)
        ->and($own['Asha Dev']['pull_requests_opened'])->toBe(3);
});

it('lists someone with pull requests in the range and no commits', function () {
    $dipa = codeActivityPerson($this->sdoc, 'Dipa Dev', team: $this->hr, gitUsername: 'dipa');
    codeActivityPullRequest($this->hrRepo, 7, 'dipa', '2026-10-25 10:00:00');

    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->hrManager)->get('/workspace/reports/code?period=custom')));

    expect($people['Dipa Dev'])->toMatchArray(['user_id' => $dipa->id, 'commits' => 0, 'pull_requests_opened' => 1]);
});

it('counts the tasks their commits are linked to, not trashed ones', function () {
    $one = Task::factory()->for($this->tax)->create();
    $two = Task::factory()->for($this->tax)->create();
    $trashed = Task::factory()->for($this->tax)->create();
    $a1 = GitCommit::query()->where('sha', 'a1')->firstOrFail();
    $a2 = GitCommit::query()->where('sha', 'a2')->firstOrFail();
    $a1->tasks()->attach([$one->id, $two->id], ['git_repository_id' => $this->taxRepo->id]);
    $a2->tasks()->attach([$one->id, $trashed->id], ['git_repository_id' => $this->taxRepo->id]);
    $trashed->delete();
    codeActivityPullRequest($this->taxRepo, 1, 'asha', '2026-10-25 10:00:00')->tasks()->attach(Task::factory()->for($this->tax)->create()->id, ['git_repository_id' => $this->taxRepo->id]);

    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom')));

    expect($people['Asha Dev']['tasks'])->toBe(2);
});

it("counts each person's commits whose tests passed and failed; running and untested count in neither", function () {
    GitCommit::query()->where('sha', 'a1')->update(['checks_state' => Checks::PASSED, 'checks' => '[]']);
    GitCommit::query()->where('sha', 'a2')->update(['checks_state' => Checks::FAILED, 'checks' => '[]']);
    codeActivityCommit($this->taxRepo, $this->asha, 'a3', '2026-10-22 09:00:00', ['checks_state' => Checks::PASSED, 'checks' => []]);
    codeActivityCommit($this->taxRepo, $this->asha, 'a4', '2026-10-22 10:00:00', ['checks_state' => Checks::RUNNING, 'checks' => []]);
    codeActivityCommit($this->taxRepo, $this->asha, 'a5', '2026-10-22 11:00:00', ['checks_state' => Checks::NONE, 'checks' => []]);

    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom')));

    expect($people['Asha Dev'])->toMatchArray(['commits' => 5, 'checks_passed' => 2, 'checks_failed' => 1])
        ->and($people['Bikash Dev'])->toMatchArray(['checks_passed' => 0, 'checks_failed' => 0]);
});

it('counts the commits in scope still waiting for their line counts', function () {
    codeActivityCommit($this->taxRepo, $this->asha, 'w1', '2026-10-25 10:00:00', ['stats_status' => null, 'stats_computed_at' => null]);
    codeActivityCommit($this->budgetRepo, $this->chandra, 'w2', '2026-10-25 10:00:00', ['stats_status' => null, 'stats_computed_at' => null]);

    expect(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom'))['uncounted'])->toBe(1)
        ->and(codeActivityReport($this->actingAs($this->boss)->get('/workspace/reports/code?period=custom'))['uncounted'])->toBe(2)
        ->and(codeActivityReport($this->actingAs($this->bikash)->get('/workspace/reports/code?period=custom'))['uncounted'])->toBe(0);
});

it('offers the projects and programs in the scope that have repositories', function () {
    Project::factory()->create(['organization_id' => $this->sdoc->id, 'title' => 'No Code']);

    $filters = fn (User $viewer): array => Arr::only(codeActivityReport($this->actingAs($viewer)->get('/workspace/reports/code?period=custom'))['options'], ['projects', 'programs']);
    $admin = $filters($this->sdocAdmin);
    $manager = $filters($this->taxManager);
    $member = $filters($this->asha);

    expect($admin)->toEqual([
        'projects' => [['slug' => 'hr-system', 'title' => 'HR System', 'label' => 'HR System'], ['slug' => 'tax-portal', 'title' => 'Tax Portal', 'label' => 'Tax Portal (Revenue)']],
        'programs' => [['slug' => $this->program->slug, 'name' => 'Revenue']],
    ])
        ->and($manager)->toEqual(['projects' => [['slug' => 'tax-portal', 'title' => 'Tax Portal', 'label' => 'Tax Portal (Revenue)']], 'programs' => []])
        ->and($member)->toEqual(['projects' => [], 'programs' => []]);
});

it("names a program's General project with its program, so two Generals never look alike", function () {
    $general = Project::factory()->create(['organization_id' => $this->sdoc->id, 'program_id' => $this->program->id, 'title' => 'General', 'slug' => 'general-revenue', 'key' => 'GR']);
    codeActivityRepository(GitConnection::query()->where('organization_id', $this->sdoc->id)->firstOrFail(), $general, 'gr-api', 4);

    $options = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom'))['options'];

    expect(collect($options['projects'])->firstWhere('slug', 'general-revenue'))
        ->toEqual(['slug' => 'general-revenue', 'title' => 'General', 'label' => 'General (Revenue)']);
});

it('asks the same number of queries however many people there are', function () {
    // The fixtures queued repository access syncs; on the sync queue they run
    // after the first request, which would put their queries in the count.
    Bus::fake([SyncRepositoryAccess::class]);

    $count = function (): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom'));

        return $queries;
    };

    // The first request also fills the sidebar's cached maintenance count.
    $count();
    $before = $count();

    foreach (['dipa', 'eka', 'gita'] as $i => $name) {
        $person = codeActivityPerson($this->sdoc, ucfirst($name).' Dev', team: $this->hr, gitUsername: $name);
        codeActivityCommit($this->hrRepo, $person, "x{$i}", '2026-10-25 10:00:00', ['code_added' => 1]);
        codeActivityPullRequest($this->hrRepo, 10 + $i, $name, '2026-10-25 10:00:00');
    }

    $after = $count();

    expect($after)->toBe($before);
});

// ---- Ranked, by period and designation ----

it('ranks people by commits by default, ties sharing a rank', function () {
    $dipa = codeActivityPerson($this->sdoc, 'Dipa Dev', team: $this->hr, gitUsername: 'dipa');
    codeActivityCommit($this->hrRepo, $this->bikash, 'w1', '2026-10-26 10:00:00');
    codeActivityCommit($this->hrRepo, $this->bikash, 'w2', '2026-10-27 10:00:00');
    codeActivityCommit($this->hrRepo, $dipa, 'w3', '2026-10-25 10:00:00');
    codeActivityCommit($this->hrRepo, $dipa, 'w4', '2026-10-28 09:00:00');
    codeActivityCommit($this->taxRepo, $this->asha, 'w5', '2026-10-26 10:00:00');
    foreach (['w6', 'w7', 'w8'] as $sha) {
        codeActivityCommit($this->taxRepo, null, $sha, '2026-10-26 11:00:00');
    }

    // The default: this week (from Sunday 2026-10-25), by commits.
    $report = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code'));

    expect(collect($report['people'])->map(fn (array $p): array => [$p['name'], $p['commits'], $p['rank']])->all())->toBe([
        ['Bikash Dev', 2, 1],
        ['Dipa Dev', 2, 1],
        ['Asha Dev', 1, 3],
        // Last and unranked, whatever its count.
        ['Not matched to anyone', 3, null],
    ]);
});

it('ranks people by code lines changed, added plus removed', function () {
    $report = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom&sort=lines'));

    expect(collect($report['people'])->map(fn (array $p): array => [$p['name'], $p['lines_changed'], $p['rank']])->all())->toBe([
        ['Asha Dev', 18, 1],
        ['Bikash Dev', 3, 2],
        ['Not matched to anyone', 8, null],
    ])
        ->and($report['filters']['sort'])->toBe('lines');
});

it('ranks people by commits whose tests passed, or by active days', function () {
    GitCommit::query()->where('sha', 'b1')->update(['checks_state' => Checks::PASSED, 'checks' => '[]']);
    GitCommit::query()->where('sha', 'a1')->update(['checks_state' => Checks::FAILED, 'checks' => '[]']);

    $tests = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom&sort=tests'));
    $days = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom&sort=active_days'));

    expect(collect($tests['people'])->map(fn (array $p): array => [$p['name'], $p['checks_passed'], $p['rank']])->all())->toBe([
        ['Bikash Dev', 1, 1],
        ['Asha Dev', 0, 2],
        ['Not matched to anyone', 0, null],
    ])
        ->and(collect($days['people'])->map(fn (array $p): array => [$p['name'], $p['active_days'], $p['rank']])->all())->toBe([
            ['Asha Dev', 2, 1],
            ['Bikash Dev', 1, 2],
            ['Not matched to anyone', 1, null],
        ]);
});

it('counts repositories a person linked or created in the range', function () {
    Queue::fake();
    $sdocGit = GitConnection::query()->where('owner', 'sdoc')->firstOrFail();
    $mofGit = GitConnection::query()->where('owner', 'mof')->firstOrFail();

    // Linked last week.
    CarbonImmutable::setTestNow('2026-10-21 10:00:00');
    $this->actingAs($this->taxManager);
    codeActivityRepository($sdocGit, $this->tax, 'tp-old', 11);
    CarbonImmutable::setTestNow('2026-10-28 10:00:00');

    // Created through the tracker: made on the forge, then linked.
    $forge = codeActivityForgeRepository('tp-web', 701);
    Http::preventStrayRequests();
    Http::fake([
        'git.example/api/v1/orgs/sdoc/repos' => Http::response($forge, 201),
        'git.example/api/v1/repos/sdoc/tp-web/branch_protections/main' => Http::response(['message' => 'The target couldn\'t be found.'], 404),
        'git.example/api/v1/repos/sdoc/tp-web/branch_protections' => Http::response(['rule_name' => 'main', 'branch_name' => 'main'], 201),
        'git.example/api/v1/repos/sdoc/tp-web/branches/main' => Http::response(['name' => 'main']),
        'git.example/api/v1/repos/sdoc/tp-web' => Http::response($forge),
        'git.example/api/v1/repos/sdoc/tp-web/collaborators*' => Http::response([]),
    ]);
    $this->actingAs($this->taxManager)->post('/workspace/projects/tax-portal/repositories/new', ['name' => 'tp-web'])->assertSessionHasNoErrors();

    // Linked from the forge, and one in another organization's project.
    $this->actingAs($this->taxManager);
    codeActivityRepository($sdocGit, $this->tax, 'tp-docs', 12);
    codeActivityRepository($mofGit, $this->budget, 'bu-web', 13);

    $week = codeActivityPeople(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code')));
    $lastWeek = codeActivityPeople(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=last_week')));
    $hr = codeActivityPeople(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?project=hr-system')));
    // Their own, wherever they are, as with commits.
    $own = codeActivityPeople(codeActivityReport($this->actingAs($this->taxManager)->get('/workspace/reports/code')));

    expect($week['Tara Manager'])->toMatchArray(['user_id' => $this->taxManager->id, 'repositories_created' => 2, 'commits' => 0])
        ->and($lastWeek['Tara Manager']['repositories_created'])->toBe(1)
        ->and($lastWeek['Asha Dev']['repositories_created'])->toBe(0)
        ->and($hr)->not->toHaveKey('Tara Manager')
        ->and($own['Tara Manager']['repositories_created'])->toBe(3);
});

it('filters by designation, offering the designations of the organizations in scope by rank', function () {
    $senior = Designation::query()->where(['organization_id' => $this->sdoc->id, 'name' => 'Senior developer'])->firstOrFail();
    $senior->update(['rank' => 20]);
    $developer = Designation::query()->create(['organization_id' => $this->sdoc->id, 'name' => 'Developer', 'rank' => 30]);
    $analyst = Designation::query()->create(['organization_id' => $this->sdoc->id, 'name' => 'Analyst', 'rank' => 30]);
    Designation::query()->create(['organization_id' => $this->mof->id, 'name' => 'Budget officer', 'rank' => 10]);
    WorkspaceAccess::memberFor($this->bikash)?->update(['designation_id' => $developer->id]);

    $report = codeActivityReport($this->actingAs($this->sdocAdmin)->get("/workspace/reports/code?period=custom&designation={$developer->id}"));
    $drawer = $this->actingAs($this->sdocAdmin)->getJson("/workspace/reports/code/people/{$this->asha->id}?period=custom&designation={$developer->id}")->assertOk()->json('data');

    // The commits no one is matched to have no designation.
    expect(collect($report['people'])->pluck('name')->all())->toBe(['Bikash Dev'])
        ->and($report['people'][0]['rank'])->toBe(1)
        ->and($report['filters']['designation'])->toBe($developer->id)
        ->and($drawer)->toBe([])
        ->and($report['options']['designations'])->toBe([
            ['id' => $senior->id, 'name' => 'Senior developer'],
            ['id' => $analyst->id, 'name' => 'Analyst'],
            ['id' => $developer->id, 'name' => 'Developer'],
        ]);
});

it("refuses a designation of an organization outside the viewer's scope", function () {
    $senior = Designation::query()->where(['organization_id' => $this->sdoc->id, 'name' => 'Senior developer'])->firstOrFail();
    $mof = Designation::query()->create(['organization_id' => $this->mof->id, 'name' => 'Budget officer', 'rank' => 10]);

    $outside = $this->actingAs($this->sdocAdmin)->getJson("/workspace/reports/code?designation={$mof->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('designation')
        ->assertJsonMissingPath('people');
    $unknown = $this->actingAs($this->sdocAdmin)->getJson('/workspace/reports/code?designation=999999')->assertUnprocessable();

    // The same words either way: a refusal never confirms a designation exists.
    expect($outside->json('errors.designation'))->toBe($unknown->json('errors.designation'));

    $this->actingAs($this->mofAdmin)->getJson("/workspace/reports/code?designation={$senior->id}")->assertUnprocessable();
    // A member sees only their own commits, in no organization's whole.
    $this->actingAs($this->asha)->getJson("/workspace/reports/code?designation={$senior->id}")->assertUnprocessable();
    $this->actingAs($this->asha)->getJson("/workspace/reports/code/people/{$this->asha->id}?designation={$senior->id}")->assertUnprocessable();
    $this->actingAs($this->sdocAdmin)->get("/workspace/reports/code?designation={$mof->id}")->assertSessionHasErrors('designation');

    // A project manager filters by their project's organization's; a super-admin by any.
    $this->actingAs($this->taxManager)->get("/workspace/reports/code?designation={$senior->id}")->assertOk();
    $this->actingAs($this->boss)->get("/workspace/reports/code?designation={$mof->id}")->assertOk();
});

it('resolves today, this week, last week and this month', function () {
    CarbonImmutable::setTestNow('2026-11-04 10:00:00'); // a Wednesday
    $filters = fn (string $query): array => codeActivityReport($this->actingAs($this->sdocAdmin)->get("/workspace/reports/code?{$query}"))['filters'];

    expect($filters('period=today'))->toMatchArray(['period' => 'today', 'from' => '2026-11-04', 'to' => '2026-11-04'])
        ->and($filters('period=week'))->toMatchArray(['period' => 'week', 'from' => '2026-11-01', 'to' => '2026-11-04'])
        ->and($filters(''))->toMatchArray(['period' => 'week', 'from' => '2026-11-01', 'to' => '2026-11-04'])
        ->and($filters('period=last_week'))->toMatchArray(['period' => 'last_week', 'from' => '2026-10-25', 'to' => '2026-10-31'])
        ->and($filters('period=month'))->toMatchArray(['period' => 'month', 'from' => '2026-11-01', 'to' => '2026-11-04'])
        ->and($filters('period=custom&from=2026-10-01&to=2026-10-10'))->toMatchArray(['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-10'])
        // A range without a period is a custom one, as links from before periods were.
        ->and($filters('from=2026-10-01&to=2026-10-10'))->toMatchArray(['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-10'])
        // Any other period leaves a stray range aside.
        ->and($filters('period=today&from=2026-10-01&to=2026-10-10'))->toMatchArray(['period' => 'today', 'from' => '2026-11-04', 'to' => '2026-11-04']);

    $this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=yesterday')->assertSessionHasErrors('period');
    $this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?sort=loudest')->assertSessionHasErrors('sort');
});

it('asks the same number of queries for 2 people as for 6, ranked, by designation, with repositories created', function () {
    // The fixtures queue repository access syncs; on the sync queue they run
    // after a request, which would put their queries in the count.
    Bus::fake([SyncRepositoryAccess::class]);
    $developer = Designation::query()->create(['organization_id' => $this->sdoc->id, 'name' => 'Developer', 'rank' => 30]);
    $sdocGit = GitConnection::query()->where('owner', 'sdoc')->firstOrFail();

    $add = function (int $from, int $to) use ($developer, $sdocGit): void {
        foreach (range($from, $to) as $i) {
            $person = codeActivityPerson($this->sdoc, "Dev {$i}", team: $this->hr, gitUsername: "dev{$i}");
            WorkspaceAccess::memberFor($person)?->update(['designation_id' => $developer->id]);
            codeActivityCommit($this->hrRepo, $person, "d{$i}", '2026-10-26 10:00:00', ['code_added' => $i]);
            codeActivityPullRequest($this->hrRepo, 100 + $i, "dev{$i}", '2026-10-26 10:00:00');
            $this->actingAs($person);
            codeActivityRepository($sdocGit, $this->hr, "hr-{$i}", 100 + $i);
        }
    };

    $count = function () use ($developer): array {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $people = codeActivityReport($this->actingAs($this->sdocAdmin)->get("/workspace/reports/code?sort=lines&designation={$developer->id}"))['people'];

        return [$queries, count($people)];
    };

    $add(1, 2);
    // The first request also fills the sidebar's cached maintenance count.
    $count();
    [$two, $twoPeople] = $count();
    $add(3, 6);
    [$six, $sixPeople] = $count();

    expect([$twoPeople, $sixPeople])->toBe([2, 6])
        ->and($six)->toBe($two);
});

it('shares gitEnabled, which shows Code activity in the sidebar', function () {
    $noGit = codeActivityPerson(Organization::factory()->create(['name' => 'No git']), 'Out Sider');
    $shared = function (User $user): bool {
        $gitEnabled = null;
        $this->actingAs($user)->get('/workspace/my')->assertInertia(function (AssertableInertia $page) use (&$gitEnabled): void {
            $gitEnabled = $page->toArray()['props']['gitEnabled'];
        });

        return (bool) $gitEnabled;
    };

    expect($shared($this->asha))->toBeTrue()
        ->and($shared($noGit))->toBeFalse();
});

// ---- Compare ----

it('gives a daily series for each person compared, one entry per day in the range', function () {
    $report = codeActivityReport($this->actingAs($this->sdocAdmin)->get("/workspace/reports/code?period=custom&members[]={$this->asha->id}&members[]={$this->bikash->id}"));

    $series = collect($report['series'])->keyBy('user_id');
    $asha = collect($series[$this->asha->id]['days'])->keyBy('date');

    expect($report['series'])->toHaveCount(2)
        ->and($series[$this->asha->id]['days'])->toHaveCount(30)
        ->and($series[$this->bikash->id]['days'])->toHaveCount(30)
        ->and($series[$this->asha->id]['days'][0])->toBe(['date' => '2026-09-29', 'code_changed' => 0, 'commits' => 0])
        ->and($asha['2026-10-20'])->toBe(['date' => '2026-10-20', 'code_changed' => 12, 'commits' => 1])
        ->and($asha['2026-10-21'])->toBe(['date' => '2026-10-21', 'code_changed' => 6, 'commits' => 1])
        ->and($report['filters']['members'])->toBe([$this->asha->id, $this->bikash->id])
        ->and(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom'))['series'])->toBe([]);
});

it('gives nothing but zeros for someone compared outside the scope', function () {
    $report = codeActivityReport($this->actingAs($this->hrManager)->get("/workspace/reports/code?period=custom&members[]={$this->asha->id}"));

    expect(collect($report['series'][0]['days'])->sum('code_changed'))->toBe(0)
        ->and(collect($report['series'][0]['days'])->sum('commits'))->toBe(0)
        ->and(collect($report['people'])->pluck('name')->all())->toBe(['Bikash Dev']);
});

it('compares at most four people', function () {
    $query = collect(range(1, 5))->map(fn (int $id) => "members[]={$id}")->implode('&');

    $this->actingAs($this->sdocAdmin)->get("/workspace/reports/code?period=custom&{$query}")->assertSessionHasErrors('members');
});

it('takes a range of at most 366 days, its end not before its start', function () {
    $this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom&from=2025-10-29&to=2026-10-30')->assertSessionHasErrors('to');
    $this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom&from=2026-10-20&to=2026-10-19')->assertSessionHasErrors('to');
    $this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom&from=2026-02-30')->assertSessionHasErrors('from');

    $year = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?period=custom&from=2025-10-30&to=2026-10-30'));

    expect($year['range'])->toBe(['from' => '2025-10-30', 'to' => '2026-10-30']);
});

// ---- One person ----

it("lists a person's commits in the scope, newest first, with the first line of each message", function () {
    $commits = $this->actingAs($this->sdocAdmin)->getJson("/workspace/reports/code/people/{$this->asha->id}?period=custom")->assertOk()->json('data');

    expect(collect($commits)->pluck('sha')->all())->toBe(['a2', 'a1'])
        ->and($commits[0])->toEqual([
            'sha' => 'a2',
            'message' => 'TP-1 Add the login form',
            'repository' => 'sdoc/tp-api',
            'url' => 'https://git.example/sdoc/tp-api/commit/a2',
            'committed_at' => CarbonImmutable::parse('2026-10-21 15:00:00')->toIso8601String(),
            'code_added' => 5,
            'code_removed' => 1,
            'comment_added' => 0,
            'comment_removed' => 0,
            'stats_status' => 'ok',
            'checks' => null,
        ]);
});

it('gives an empty list for a person outside the scope, or no one at all', function () {
    $this->actingAs($this->hrManager)->getJson("/workspace/reports/code/people/{$this->asha->id}?period=custom")->assertOk()->assertExactJson(['data' => []]);
    $this->actingAs($this->bikash)->getJson("/workspace/reports/code/people/{$this->asha->id}?period=custom")->assertOk()->assertExactJson(['data' => []]);
    $this->actingAs($this->sdocAdmin)->getJson("/workspace/reports/code/people/{$this->chandra->id}?period=custom")->assertOk()->assertExactJson(['data' => []]);
    $this->actingAs($this->sdocAdmin)->getJson('/workspace/reports/code/people/999999?period=custom')->assertOk()->assertExactJson(['data' => []]);
});

it("keeps a person's drill-down to the range and the filters", function () {
    codeActivityCommit($this->budgetRepo, $this->asha, 'a3', '2026-10-22 10:00:00');

    $own = $this->actingAs($this->asha)->getJson("/workspace/reports/code/people/{$this->asha->id}?period=custom&from=2026-10-21&to=2026-10-28")->json('data');
    $mof = $this->actingAs($this->mofAdmin)->getJson("/workspace/reports/code/people/{$this->asha->id}?period=custom")->json('data');

    expect(collect($own)->pluck('sha')->all())->toBe(['a3', 'a2'])
        ->and(collect($mof)->pluck('sha')->all())->toBe(['a3']);
});
