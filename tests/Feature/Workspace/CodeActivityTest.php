<?php

use App\Jobs\SyncRepositoryAccess;
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
use App\Support\Git\GitAccess;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
 * The code activity report (/workspace/reports/code): who committed what,
 * scoped to what each viewer runs. Two organizations with git: SDOC (a
 * Revenue program holding Tax Portal, and HR System outside any program)
 * and MoF (Budget). The default range is the last 30 days: 2026-09-29 to
 * 2026-10-28.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    CarbonImmutable::setTestNow('2026-10-28 10:00:00');

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
    $report = codeActivityReport($this->actingAs($this->boss)->get('/workspace/reports/code'));

    expect(collect($report['people'])->pluck('name')->all())->toBe(['Asha Dev', 'Bikash Dev', 'Chandra Dev', 'Not matched to anyone']);
});

it('never ranks people by how much code they wrote: the order is by name, in any case', function () {
    $bina = codeActivityPerson($this->sdoc, 'bina Dev', team: $this->hr, gitUsername: 'bina');
    codeActivityCommit($this->hrRepo, $bina, 'n1', '2026-10-25 10:00:00', ['code_added' => 5000]);
    codeActivityCommit($this->hrRepo, null, 'u9', '2026-10-25 11:00:00', ['code_added' => 9000]);

    $report = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code'));

    expect(collect($report['people'])->pluck('name')->all())->toBe(['Asha Dev', 'Bikash Dev', 'bina Dev', 'Not matched to anyone']);
});

it('shows an organizational admin only their organization, with the commits no one is matched to', function () {
    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code')));

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
    expect(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code'))['repositories'])->toBe(2)
        ->and(codeActivityReport($this->actingAs($this->mofAdmin)->get('/workspace/reports/code'))['repositories'])->toBe(1);

    GitRepository::query()->delete();

    expect(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code'))['repositories'])->toBe(0);
});

it('shows an organizational admin of another organization nobody of this one', function () {
    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->mofAdmin)->get('/workspace/reports/code')));

    expect(array_keys($people))->toBe(['Chandra Dev']);
});

it("shows a program manager only their programs' projects", function () {
    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->programManager)->get('/workspace/reports/code')));

    expect(array_keys($people))->toBe(['Asha Dev', 'Not matched to anyone']);
});

it('shows a project manager only the projects they manage', function () {
    $tax = codeActivityPeople(codeActivityReport($this->actingAs($this->taxManager)->get('/workspace/reports/code')));
    $hr = codeActivityPeople(codeActivityReport($this->actingAs($this->hrManager)->get('/workspace/reports/code')));

    expect(array_keys($tax))->toBe(['Asha Dev', 'Not matched to anyone'])
        ->and(array_keys($hr))->toBe(['Bikash Dev']);
});

it('shows a member only their own row, never the commits no one is matched to', function () {
    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->asha)->get('/workspace/reports/code')));

    expect(array_keys($people))->toBe(['Asha Dev'])
        ->and($people['Asha Dev']['commits'])->toBe(2);
});

it("counts a person's own commits wherever they are, and each repository's owners see only theirs", function () {
    codeActivityCommit($this->budgetRepo, $this->asha, 'a3', '2026-10-22 10:00:00', ['code_added' => 100]);

    $own = codeActivityPeople(codeActivityReport($this->actingAs($this->asha)->get('/workspace/reports/code')));
    $sdoc = codeActivityPeople(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code')));
    $mof = codeActivityPeople(codeActivityReport($this->actingAs($this->mofAdmin)->get('/workspace/reports/code')));

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

    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->programManager)->get('/workspace/reports/code')));

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

    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code')));

    expect($people['Asha Dev']['commits'])->toBe(2)
        ->and($people['Asha Dev']['code_added'])->toBe(15);
});

it('counts only commits inside the range, both ends whole days', function () {
    codeActivityCommit($this->taxRepo, $this->asha, 'early', '2026-10-19 23:59:59', ['code_added' => 1000]);
    codeActivityCommit($this->taxRepo, $this->asha, 'old', '2026-09-28 23:59:59', ['code_added' => 2000]);

    $default = codeActivityPeople(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code')));
    $oneDay = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?from=2026-10-20&to=2026-10-20'));

    expect($default['Asha Dev']['commits'])->toBe(3)
        ->and($default['Asha Dev']['code_added'])->toBe(1015)
        ->and($oneDay['range'])->toBe(['from' => '2026-10-20', 'to' => '2026-10-20'])
        ->and(collect($oneDay['people'])->pluck('name')->all())->toBe(['Asha Dev'])
        ->and($oneDay['people'][0]['code_added'])->toBe(10);
});

it('echoes the range and filters it applied, defaulting to the last 30 days', function () {
    $report = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code'));

    expect($report['range'])->toBe(['from' => '2026-09-29', 'to' => '2026-10-28'])
        ->and($report['filters'])->toEqual(['from' => '2026-09-29', 'to' => '2026-10-28', 'project' => null, 'program' => null, 'members' => []]);
});

it('narrows to a project or a program inside the scope', function () {
    $hr = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?project=hr-system'));
    $revenue = codeActivityReport($this->actingAs($this->sdocAdmin)->get("/workspace/reports/code?program={$this->program->slug}"));

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

    $sdoc = codeActivityPeople(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code')));
    $own = codeActivityPeople(codeActivityReport($this->actingAs($this->asha)->get('/workspace/reports/code')));

    expect($sdoc['Asha Dev']['pull_requests_opened'])->toBe(2)
        ->and($sdoc['Asha Dev']['pull_requests_merged'])->toBe(2)
        ->and($own['Asha Dev']['pull_requests_opened'])->toBe(3);
});

it('lists someone with pull requests in the range and no commits', function () {
    $dipa = codeActivityPerson($this->sdoc, 'Dipa Dev', team: $this->hr, gitUsername: 'dipa');
    codeActivityPullRequest($this->hrRepo, 7, 'dipa', '2026-10-25 10:00:00');

    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->hrManager)->get('/workspace/reports/code')));

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

    $people = codeActivityPeople(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code')));

    expect($people['Asha Dev']['tasks'])->toBe(2);
});

it('counts the commits in scope still waiting for their line counts', function () {
    codeActivityCommit($this->taxRepo, $this->asha, 'w1', '2026-10-25 10:00:00', ['stats_status' => null, 'stats_computed_at' => null]);
    codeActivityCommit($this->budgetRepo, $this->chandra, 'w2', '2026-10-25 10:00:00', ['stats_status' => null, 'stats_computed_at' => null]);

    expect(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code'))['uncounted'])->toBe(1)
        ->and(codeActivityReport($this->actingAs($this->boss)->get('/workspace/reports/code'))['uncounted'])->toBe(2)
        ->and(codeActivityReport($this->actingAs($this->bikash)->get('/workspace/reports/code'))['uncounted'])->toBe(0);
});

it('offers the projects and programs in the scope that have repositories', function () {
    Project::factory()->create(['organization_id' => $this->sdoc->id, 'title' => 'No Code']);

    $admin = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code'))['options'];
    $manager = codeActivityReport($this->actingAs($this->taxManager)->get('/workspace/reports/code'))['options'];
    $member = codeActivityReport($this->actingAs($this->asha)->get('/workspace/reports/code'))['options'];

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

    $options = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code'))['options'];

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
        codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code'));

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

// ---- Compare ----

it('gives a daily series for each person compared, one entry per day in the range', function () {
    $report = codeActivityReport($this->actingAs($this->sdocAdmin)->get("/workspace/reports/code?members[]={$this->asha->id}&members[]={$this->bikash->id}"));

    $series = collect($report['series'])->keyBy('user_id');
    $asha = collect($series[$this->asha->id]['days'])->keyBy('date');

    expect($report['series'])->toHaveCount(2)
        ->and($series[$this->asha->id]['days'])->toHaveCount(30)
        ->and($series[$this->bikash->id]['days'])->toHaveCount(30)
        ->and($series[$this->asha->id]['days'][0])->toBe(['date' => '2026-09-29', 'code_changed' => 0, 'commits' => 0])
        ->and($asha['2026-10-20'])->toBe(['date' => '2026-10-20', 'code_changed' => 12, 'commits' => 1])
        ->and($asha['2026-10-21'])->toBe(['date' => '2026-10-21', 'code_changed' => 6, 'commits' => 1])
        ->and($report['filters']['members'])->toBe([$this->asha->id, $this->bikash->id])
        ->and(codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code'))['series'])->toBe([]);
});

it('gives nothing but zeros for someone compared outside the scope', function () {
    $report = codeActivityReport($this->actingAs($this->hrManager)->get("/workspace/reports/code?members[]={$this->asha->id}"));

    expect(collect($report['series'][0]['days'])->sum('code_changed'))->toBe(0)
        ->and(collect($report['series'][0]['days'])->sum('commits'))->toBe(0)
        ->and(collect($report['people'])->pluck('name')->all())->toBe(['Bikash Dev']);
});

it('compares at most four people', function () {
    $query = collect(range(1, 5))->map(fn (int $id) => "members[]={$id}")->implode('&');

    $this->actingAs($this->sdocAdmin)->get("/workspace/reports/code?{$query}")->assertSessionHasErrors('members');
});

it('takes a range of at most 366 days, its end not before its start', function () {
    $this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?from=2025-10-29&to=2026-10-30')->assertSessionHasErrors('to');
    $this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?from=2026-10-20&to=2026-10-19')->assertSessionHasErrors('to');
    $this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?from=2026-02-30')->assertSessionHasErrors('from');

    $year = codeActivityReport($this->actingAs($this->sdocAdmin)->get('/workspace/reports/code?from=2025-10-30&to=2026-10-30'));

    expect($year['range'])->toBe(['from' => '2025-10-30', 'to' => '2026-10-30']);
});

// ---- One person ----

it("lists a person's commits in the scope, newest first, with the first line of each message", function () {
    $commits = $this->actingAs($this->sdocAdmin)->getJson("/workspace/reports/code/people/{$this->asha->id}")->assertOk()->json('data');

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
        ]);
});

it('gives an empty list for a person outside the scope, or no one at all', function () {
    $this->actingAs($this->hrManager)->getJson("/workspace/reports/code/people/{$this->asha->id}")->assertOk()->assertExactJson(['data' => []]);
    $this->actingAs($this->bikash)->getJson("/workspace/reports/code/people/{$this->asha->id}")->assertOk()->assertExactJson(['data' => []]);
    $this->actingAs($this->sdocAdmin)->getJson("/workspace/reports/code/people/{$this->chandra->id}")->assertOk()->assertExactJson(['data' => []]);
    $this->actingAs($this->sdocAdmin)->getJson('/workspace/reports/code/people/999999')->assertOk()->assertExactJson(['data' => []]);
});

it("keeps a person's drill-down to the range and the filters", function () {
    codeActivityCommit($this->budgetRepo, $this->asha, 'a3', '2026-10-22 10:00:00');

    $own = $this->actingAs($this->asha)->getJson("/workspace/reports/code/people/{$this->asha->id}?from=2026-10-21&to=2026-10-28")->json('data');
    $mof = $this->actingAs($this->mofAdmin)->getJson("/workspace/reports/code/people/{$this->asha->id}")->json('data');

    expect(collect($own)->pluck('sha')->all())->toBe(['a3', 'a2'])
        ->and(collect($mof)->pluck('sha')->all())->toBe(['a3']);
});
