<?php

use App\Models\GitBranch;
use App\Models\GitCommit;
use App\Models\GitConnection;
use App\Models\GitPullRequest;
use App\Models\GitRepository;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

/*
 * The project's Code tab (`git` on the project page), the task's Code
 * section (`code` on the task preview and the API task show) and the board's
 * pull-request mark (`has_pull_request` on each task). All of it is absent
 * for an organization without git.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    CarbonImmutable::setTestNow('2026-10-28 10:00:00');

    $this->organization = Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Tax Portal', 'key' => 'TP']);
    $this->task = Task::factory()->for($this->project)->create(['title' => 'Fix login']);
    $this->manager = codeViewActor('manager', $this->project);
    $this->member = codeViewActor('member', $this->project);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function codeViewActor(string $role, Project $project): User
{
    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create([
        'organization_id' => $project->organization_id,
        'is_office_admin' => $role === 'office admin',
    ]);

    if ($role !== 'office admin') {
        $project->ensureTeam()->members()->attach($member->id, ['role' => $role === 'manager' ? 'leader' : 'member']);
    }

    return $user;
}

function codeViewGit(Organization $organization): GitConnection
{
    return GitConnection::query()->create([
        'organization_id' => $organization->id,
        'base_url' => 'https://git.example',
        'owner' => 'sdoc',
        'token' => 'bot-token-never-shown',
        'webhook_id' => 99,
        'webhook_secret' => 'hook-secret-never-shown',
    ]);
}

function codeViewRepository(GitConnection $connection, Project $project, string $name, int $forgeId): GitRepository
{
    return GitRepository::query()->create([
        'git_connection_id' => $connection->id,
        'project_id' => $project->id,
        'forge_id' => $forgeId,
        'owner' => 'sdoc',
        'name' => $name,
        'full_name' => "sdoc/{$name}",
        'html_url' => "https://git.example/sdoc/{$name}",
        'clone_url' => "https://git.example/sdoc/{$name}.git",
        'ssh_url' => "git@git.example:sdoc/{$name}.git",
        'default_branch' => 'main',
        'last_pushed_at' => '2026-10-27 09:00:00',
    ]);
}

/** @param  array<string, mixed>  $attributes */
function codeViewPullRequest(GitRepository $repo, int $number, string $state, string $openedAt, array $attributes = []): GitPullRequest
{
    return GitPullRequest::query()->create($attributes + [
        'git_repository_id' => $repo->id,
        'number' => $number,
        'title' => "Change {$number}",
        'body' => null,
        'state' => $state,
        'url' => "{$repo->html_url}/pulls/{$number}",
        'head_branch' => "tp-1-change-{$number}",
        'author_username' => 'bikash',
        'opened_at' => $openedAt,
        'merged_at' => $state === GitPullRequest::STATE_MERGED ? '2026-10-27 12:00:00' : null,
        'closed_at' => $state === GitPullRequest::STATE_OPEN ? null : '2026-10-27 12:00:00',
    ]);
}

function codeViewCommit(GitRepository $repo, string $sha, string $message, string $committedAt, ?string $unreachableAt = null): GitCommit
{
    return GitCommit::query()->create([
        'git_repository_id' => $repo->id,
        'sha' => $sha,
        'message' => $message,
        'author_name' => 'Bikash Dev',
        'author_username' => 'bikash',
        'url' => "{$repo->html_url}/commit/{$sha}",
        'committed_at' => $committedAt,
        'unreachable_at' => $unreachableAt,
    ]);
}

function codeViewLink(GitBranch|GitCommit|GitPullRequest $code, Task $task): void
{
    $code->tasks()->attach($task->id, ['git_repository_id' => $code->git_repository_id]);
}

it('leaves the Code tab, the task code and the board mark out for an organization without git', function () {
    $this->actingAs($this->manager)
        ->get("/workspace/projects/{$this->project->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Projects/Show')
            ->where('git', null)
            ->missing('tasks.0.has_pull_request'));

    $preview = $this->getJson("/workspace/tasks/{$this->task->id}/preview")->assertOk()->json('data');
    expect($preview)->toHaveKey('code')->and($preview['code'])->toBeNull();

    Sanctum::actingAs($this->manager, ['*']);
    $this->getJson("/api/v1/workspace/projects/{$this->project->slug}")->assertOk()->assertJsonPath('git', null);
    $this->getJson("/api/v1/workspace/projects/{$this->project->slug}/tasks/{$this->task->slug}")->assertOk()->assertJsonPath('code', null);
});

it('lists the linked repositories with their open pull requests on the project page', function () {
    $connection = codeViewGit($this->organization);
    $app = codeViewRepository($connection, $this->project, 'app', 501);
    codeViewRepository($connection, $this->project, 'api', 502);
    codeViewRepository($connection, Project::factory()->create(['organization_id' => $this->organization->id]), 'other', 503);

    // Twelve open pull requests, one merged and one closed: the ten newest open ones show.
    foreach (range(1, 12) as $n) {
        codeViewPullRequest($app, $n, GitPullRequest::STATE_OPEN, CarbonImmutable::parse('2026-10-01 08:00:00')->addDays($n)->toDateTimeString());
    }
    codeViewPullRequest($app, 13, GitPullRequest::STATE_MERGED, '2026-10-27 08:00:00');
    codeViewPullRequest($app, 14, GitPullRequest::STATE_CLOSED, '2026-10-27 09:00:00');

    $this->actingAs($this->manager)
        ->get("/workspace/projects/{$this->project->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('git.can_manage', true)
            ->has('git.repositories', 2)
            ->where('git.repositories.0.full_name', 'sdoc/api')
            ->where('git.repositories.0.open_pull_requests', [])
            ->where('git.repositories.0.open_pull_requests_count', 0)
            ->where('git.repositories.1', fn ($repo) => collect($repo)->except('open_pull_requests')->all() == [
                'id' => $app->id,
                'owner' => 'sdoc',
                'name' => 'app',
                'full_name' => 'sdoc/app',
                'html_url' => 'https://git.example/sdoc/app',
                'clone_url' => 'https://git.example/sdoc/app.git',
                'ssh_url' => 'git@git.example:sdoc/app.git',
                'default_branch' => 'main',
                'protected_branch' => 'main',
                'last_pushed_at' => '2026-10-27T09:00:00+05:45',
                'created_at' => '2026-10-28T10:00:00+05:45',
                'protects_main' => false,
                'main_checks' => null,
                // All twelve are counted; the list shows ten.
                'open_pull_requests_count' => 12,
            ])
            ->where('git.repositories.1.open_pull_requests', fn ($prs) => collect($prs)->pluck('number')->all() === [12, 11, 10, 9, 8, 7, 6, 5, 4, 3])
            ->where('git.repositories.1.open_pull_requests.0', fn ($pr) => collect($pr)->all() == [
                'id' => GitPullRequest::query()->where('number', 12)->value('id'),
                'number' => 12,
                'title' => 'Change 12',
                'state' => 'open',
                'url' => 'https://git.example/sdoc/app/pulls/12',
                'head_branch' => 'tp-1-change-12',
                'author_username' => 'bikash',
                'opened_at' => '2026-10-13T08:00:00+05:45',
                'merged_at' => null,
                'closed_at' => null,
                'checks' => null,
            ]));
});

it('lets only those who run the project link repositories', function () {
    codeViewGit($this->organization);

    $this->actingAs($this->member)
        ->get("/workspace/projects/{$this->project->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('git.can_manage', false)
            ->where('git.repositories', []));

    $this->actingAs(codeViewActor('office admin', $this->project))
        ->get("/workspace/projects/{$this->project->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('git.can_manage', true));

    Sanctum::actingAs($this->member, ['*']);
    $this->getJson("/api/v1/workspace/projects/{$this->project->slug}")
        ->assertOk()
        ->assertJsonPath('git.can_manage', false)
        ->assertJsonPath('git.repositories', []);
});

it('marks the tasks that have a pull request', function () {
    $repo = codeViewRepository(codeViewGit($this->organization), $this->project, 'app', 501);
    $quiet = Task::factory()->for($this->project)->create(['title' => 'Write the manual']);
    codeViewLink(codeViewPullRequest($repo, 1, GitPullRequest::STATE_MERGED, '2026-10-20 08:00:00'), $this->task);
    // A branch or a commit alone is not a pull request.
    $branch = GitBranch::query()->create(['git_repository_id' => $repo->id, 'name' => 'tp-2-manual', 'head_sha' => str_repeat('b', 40)]);
    codeViewLink($branch, $quiet);

    $this->actingAs($this->member)
        ->get("/workspace/projects/{$this->project->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tasks', fn ($tasks) => collect($tasks)->mapWithKeys(fn ($t) => [$t['id'] => $t['has_pull_request']])->sortKeys()->all() === [
                $this->task->id => true,
                $quiet->id => false,
            ]));
});

it('gives the task preview its pull requests, branches, commits and branch name', function () {
    $connection = codeViewGit($this->organization);
    $app = codeViewRepository($connection, $this->project, 'app', 501);
    $api = codeViewRepository($connection, $this->project, 'api', 502);

    // Open first, then the most recently opened; ten at most.
    $merged = codeViewPullRequest($app, 1, GitPullRequest::STATE_MERGED, '2026-10-25 08:00:00', ['title' => 'Fix the login form']);
    $open = codeViewPullRequest($api, 7, GitPullRequest::STATE_OPEN, '2026-10-02 08:00:00', ['head_branch' => 'tp-1-fix-login']);
    $closed = codeViewPullRequest($app, 2, GitPullRequest::STATE_CLOSED, '2026-10-26 08:00:00');
    foreach ([$merged, $open, $closed] as $pr) {
        codeViewLink($pr, $this->task);
    }
    foreach (range(3, 11) as $n) {
        codeViewLink(codeViewPullRequest($app, $n, GitPullRequest::STATE_CLOSED, '2026-09-0'.min($n - 2, 9).' 08:00:00'), $this->task);
    }
    // Another task's pull request stays off.
    codeViewPullRequest($app, 20, GitPullRequest::STATE_OPEN, '2026-10-27 08:00:00');

    $branch = GitBranch::query()->create(['git_repository_id' => $api->id, 'name' => 'tp-1-fix-login', 'head_sha' => str_repeat('a', 40)]);
    $nested = GitBranch::query()->create(['git_repository_id' => $app->id, 'name' => 'feature/TP-1#login', 'head_sha' => str_repeat('c', 40)]);
    codeViewLink($branch, $this->task);
    codeViewLink($nested, $this->task);

    // Twelve commits: the latest ten, newest first, an unreachable one flagged.
    foreach (range(1, 12) as $n) {
        $sha = sprintf('%02d', $n).str_repeat('f', 38);
        $commit = codeViewCommit(
            $app,
            $sha,
            "TP-1 step {$n}\n\nWhy it was needed.",
            CarbonImmutable::parse('2026-10-10 08:00:00')->addDays($n)->toDateTimeString(),
            $n === 12 ? '2026-10-27 00:00:00' : null,
        );
        codeViewLink($commit, $this->task);
    }

    $code = $this->actingAs($this->member)->getJson("/workspace/tasks/{$this->task->id}/preview")->assertOk()->json('data.code');

    expect($code['branch_name'])->toBe('tp-1-fix-login')
        ->and(collect($code['pull_requests'])->map(fn ($pr) => "{$pr['repository']}#{$pr['number']}")->all())
        ->toBe(['sdoc/api#7', 'sdoc/app#2', 'sdoc/app#1', 'sdoc/app#11', 'sdoc/app#10', 'sdoc/app#9', 'sdoc/app#8', 'sdoc/app#7', 'sdoc/app#6', 'sdoc/app#5'])
        ->and($code['pull_requests'][2])->toEqual([
            'id' => $merged->id,
            'number' => 1,
            'title' => 'Fix the login form',
            'state' => 'merged',
            'url' => 'https://git.example/sdoc/app/pulls/1',
            'head_branch' => 'tp-1-change-1',
            'author_username' => 'bikash',
            'repository' => 'sdoc/app',
            'opened_at' => '2026-10-25T08:00:00+05:45',
            'merged_at' => '2026-10-27T12:00:00+05:45',
            'closed_at' => '2026-10-27T12:00:00+05:45',
            'checks' => null,
        ])
        ->and($code['branches'])->toEqual([
            ['id' => $nested->id, 'name' => 'feature/TP-1#login', 'repository' => 'sdoc/app', 'url' => 'https://git.example/sdoc/app/src/branch/feature/TP-1%23login'],
            ['id' => $branch->id, 'name' => 'tp-1-fix-login', 'repository' => 'sdoc/api', 'url' => 'https://git.example/sdoc/api/src/branch/tp-1-fix-login'],
        ])
        ->and(collect($code['commits'])->pluck('subject')->all())->toBe(array_map(fn ($n) => "TP-1 step {$n}", range(12, 3)))
        ->and($code['commits'][0])->toEqual([
            'id' => GitCommit::query()->where('sha', '12'.str_repeat('f', 38))->value('id'),
            'subject' => 'TP-1 step 12',
            'short_sha' => '12fffff',
            'url' => 'https://git.example/sdoc/app/commit/12'.str_repeat('f', 38),
            'author' => 'Bikash Dev',
            'repository' => 'sdoc/app',
            'committed_at' => '2026-10-22T08:00:00+05:45',
            'unreachable' => true,
            'checks' => null,
        ])
        ->and($code['commits'][1]['unreachable'])->toBeFalse();
});

it('names the branch after the task code and the start of its title', function (string $title, string $expected) {
    codeViewGit($this->organization);
    $task = Task::factory()->for($this->project)->create(['title' => $title]);

    expect($this->actingAs($this->member)->getJson("/workspace/tasks/{$task->id}/preview")->json('data.code.branch_name'))
        ->toBe(str_replace('{n}', (string) $task->number, $expected));
})->with([
    'a short title' => ['Fix login: SSO + OTP (v2)', 'tp-{n}-fix-login-sso-otp-v2'],
    'the first forty characters' => ['Fix the login page so it remembers the officer across restarts', 'tp-{n}-fix-the-login-page-so-it-remembers-the-o'],
    'nothing to spell' => ['!!!', 'tp-{n}'],
]);

it('gives the task code on the API task show and preview, by the same rule', function () {
    $repo = codeViewRepository(codeViewGit($this->organization), $this->project, 'app', 501);
    codeViewLink(codeViewPullRequest($repo, 4, GitPullRequest::STATE_OPEN, '2026-10-20 08:00:00'), $this->task);

    Sanctum::actingAs($this->member, ['*']);

    $show = $this->getJson("/api/v1/workspace/projects/{$this->project->slug}/tasks/{$this->task->slug}")->assertOk()->json('code');
    $preview = $this->getJson("/api/v1/workspace/tasks/{$this->task->id}/preview")->assertOk()->json('data.code');

    expect($show['branch_name'])->toBe('tp-1-fix-login')
        ->and(collect($show['pull_requests'])->pluck('number')->all())->toBe([4])
        ->and($show['branches'])->toBe([])
        ->and($show['commits'])->toBe([])
        ->and($preview)->toEqual($show);

    $this->getJson("/api/v1/workspace/projects/{$this->project->slug}")
        ->assertOk()
        ->assertJsonPath('tasks.0.has_pull_request', true)
        ->assertJsonPath('git.repositories.0.open_pull_requests.0.number', 4);
});

it('keeps the code of a project from someone who cannot view it', function () {
    codeViewGit($this->organization);
    $outsider = User::factory()->create();
    Member::factory()->linkedTo($outsider)->create(['organization_id' => $this->organization->id]);

    $this->actingAs($outsider)->get("/workspace/projects/{$this->project->slug}")->assertForbidden();
    $this->actingAs($outsider)->getJson("/workspace/tasks/{$this->task->id}/preview")->assertForbidden();
});

it('leaves code from a repository of a project the viewer cannot see off the task', function () {
    $connection = codeViewGit($this->organization);
    // Another project of the same office, private, with the member off its team.
    $secret = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Secret Work', 'key' => 'SW', 'is_public' => false]);
    $hidden = codeViewRepository($connection, $secret, 'secret', 601);
    // Its pull request, branch and commit name TP-1, so they link to the task.
    $hiddenPullRequest = codeViewPullRequest($hidden, 3, GitPullRequest::STATE_OPEN, '2026-10-27 08:00:00', ['title' => 'TP-1 Borrow the login fix']);
    codeViewLink($hiddenPullRequest, $this->task);
    codeViewLink(GitBranch::query()->create(['git_repository_id' => $hidden->id, 'name' => 'tp-1-borrow', 'head_sha' => str_repeat('d', 40)]), $this->task);
    codeViewLink(codeViewCommit($hidden, str_repeat('d', 40), 'TP-1 Borrow the login fix', '2026-10-27 08:00:00'), $this->task);
    // Its merge neither: the task's activity is for everyone who sees the task.
    $hiddenPullRequest->update(['state' => GitPullRequest::STATE_MERGED, 'merged_at' => '2026-10-28 08:00:00', 'closed_at' => '2026-10-28 08:00:00']);

    $hiddenFrom = function (User $viewer): void {
        $empty = ['pull_requests' => [], 'branches' => [], 'commits' => []];

        $preview = $this->actingAs($viewer)->getJson("/workspace/tasks/{$this->task->id}/preview")->assertOk()->json('data');
        expect(collect($preview['code'])->only(array_keys($empty))->all())->toBe($empty)
            ->and($preview['code']['branch_name'])->toBe('tp-1-fix-login')
            ->and(collect($preview['activity'])->pluck('description')->filter(fn (string $d) => str_contains($d, 'Pull request'))->all())->toBe([]);

        $this->actingAs($viewer)->get('/workspace/notifications?tab=activity')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('activity.data', fn ($rows) => collect($rows)->pluck('description')->filter(fn (string $d) => str_contains($d, 'Pull request'))->isEmpty()));

        $this->actingAs($viewer)->get("/workspace/projects/{$this->project->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tasks.0.has_pull_request', false));

        Sanctum::actingAs($viewer, ['*']);
        $show = $this->getJson("/api/v1/workspace/projects/{$this->project->slug}/tasks/{$this->task->slug}")->assertOk()->json('code');
        expect(collect($show)->only(array_keys($empty))->all())->toBe($empty);
        $this->getJson("/api/v1/workspace/projects/{$this->project->slug}")->assertOk()->assertJsonPath('tasks.0.has_pull_request', false);
    };

    $shownTo = function (User $viewer): void {
        $preview = $this->actingAs($viewer)->getJson("/workspace/tasks/{$this->task->id}/preview")->assertOk()->json('data.code');
        expect(collect($preview['pull_requests'])->pluck('repository')->all())->toBe(['sdoc/secret'])
            ->and(collect($preview['branches'])->pluck('name')->all())->toBe(['tp-1-borrow'])
            ->and(collect($preview['commits'])->pluck('subject')->all())->toBe(['TP-1 Borrow the login fix']);

        $this->actingAs($viewer)->get("/workspace/projects/{$this->project->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tasks.0.has_pull_request', true));

        Sanctum::actingAs($viewer, ['*']);
        $show = $this->getJson("/api/v1/workspace/projects/{$this->project->slug}/tasks/{$this->task->slug}")->assertOk()->json('code');
        expect(collect($show['commits'])->pluck('subject')->all())->toBe(['TP-1 Borrow the login fix']);
    };

    $hiddenFrom($this->member);
    // The project's own manager is not on the other team either.
    $hiddenFrom($this->manager);
    // Nor is seeing the other project enough: its code is for its team.
    $secret->update(['is_public' => true]);
    $hiddenFrom($this->member);

    $shownTo(User::factory()->create(['email' => 'boss@example.com']));

    // Put on the other project's team, the member sees them too. Joining
    // queues an access sync of its repositories; there is no forge here.
    Queue::fake();
    $secret->ensureTeam()->members()->attach(Member::query()->where('user_id', $this->member->id)->value('id'), ['role' => 'member']);
    $shownTo($this->member);
});

it('shows code to the project\'s team, its office admins and super-admins, not to other viewers of a public project', function (string $who, bool $sees) {
    $this->project->update(['is_public' => true]);
    $repo = codeViewRepository(codeViewGit($this->organization), $this->project, 'app', 501);
    codeViewLink(codeViewPullRequest($repo, 4, GitPullRequest::STATE_OPEN, '2026-10-20 08:00:00'), $this->task);

    $viewer = match ($who) {
        'a team member' => $this->member,
        'its manager' => $this->manager,
        'an office admin' => codeViewActor('office admin', $this->project),
        'a super-admin' => User::factory()->create(['email' => 'boss@example.com']),
        'a colleague off the team' => codeViewActor('colleague', Project::factory()->create(['organization_id' => $this->organization->id])),
    };

    // Everyone here can see the project itself.
    $this->actingAs($viewer)->get("/workspace/projects/{$this->project->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $sees
            ? $page->where('git.repositories.0.full_name', 'sdoc/app')->where('tasks.0.has_pull_request', true)
            : $page->where('git', null)->missing('tasks.0.has_pull_request'));
    expect($this->getJson("/workspace/tasks/{$this->task->id}/preview")->assertOk()->json('data.code.pull_requests.0.number'))->toBe($sees ? 4 : null);
    $this->get("/workspace/projects/{$this->project->slug}/tasks/{$this->task->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('code', fn ($code) => ($code !== null) === $sees));

    Sanctum::actingAs($viewer, ['*']);
    $project = $this->getJson("/api/v1/workspace/projects/{$this->project->slug}")->assertOk();
    expect($project->json('git') !== null)->toBe($sees)
        ->and($project->json('tasks.0.has_pull_request'))->toBe($sees ? true : null)
        ->and($this->getJson("/api/v1/workspace/projects/{$this->project->slug}/tasks/{$this->task->slug}")->assertOk()->json('code') !== null)->toBe($sees)
        ->and($this->getJson("/api/v1/workspace/tasks/{$this->task->id}/preview")->assertOk()->json('data.code') !== null)->toBe($sees);
    $this->getJson("/api/v1/workspace/projects/{$this->project->slug}/repositories")->assertStatus($sees ? 200 : 403);

    // The query scope says the same as the check.
    expect(Project::query()->codeVisibleTo($viewer)->whereKey($this->project->id)->exists())->toBe($sees)
        ->and(WorkspaceAccess::canViewProjectCode($viewer, $this->project))->toBe($sees);
})->with([
    'a team member' => ['a team member', true],
    'its manager' => ['its manager', true],
    'an office admin' => ['an office admin', true],
    'a super-admin' => ['a super-admin', true],
    'a colleague off the team' => ['a colleague off the team', false],
]);

it('never shows code to a deactivated team member or office admin, who are signed out', function () {
    codeViewGit($this->organization);
    $admin = codeViewActor('office admin', $this->project);
    Member::query()->whereIn('user_id', [$this->member->id, $admin->id])->update(['is_active' => false]);
    WorkspaceAccess::flush();

    foreach ([$this->member, $admin] as $viewer) {
        expect(WorkspaceAccess::canViewProjectCode($viewer, $this->project))->toBeFalse()
            ->and(Project::query()->codeVisibleTo($viewer)->whereKey($this->project->id)->exists())->toBeFalse();

        $this->actingAs($viewer)->get("/workspace/projects/{$this->project->slug}")->assertRedirect('/workspace/login');
    }
});

it('keeps code from the scope where the organization has no git, even for a super-admin', function () {
    WorkspaceAccess::flush();
    $boss = User::factory()->create(['email' => 'boss@example.com']);

    expect(Project::query()->codeVisibleTo($boss)->exists())->toBeFalse()
        ->and(WorkspaceAccess::canViewProjectCode($boss, $this->project))->toBeFalse();

    codeViewGit($this->organization);
    WorkspaceAccess::flush();

    expect(Project::query()->codeVisibleTo($boss)->pluck('id')->all())->toBe([$this->project->id])
        ->and(WorkspaceAccess::canViewProjectCode($boss, $this->project))->toBeTrue();
});

it('asks once per request whether the organization has git', function () {
    codeViewRepository(codeViewGit($this->organization), $this->project, 'app', 501);
    $asked = 0;
    // The lookup of this organization's connection; the code scope's
    // subquery over every connection rides inside other queries.
    DB::listen(function (QueryExecuted $query) use (&$asked): void {
        $asked += preg_match('/from .git_connections. where .organization_id. = \?/', $query->sql);
    });

    $this->actingAs($this->manager)->get("/workspace/projects/{$this->project->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('git.can_manage', true));

    expect($asked)->toBe(1);
});

it('gives the full task page the same Code section', function () {
    $this->actingAs($this->member)->get("/workspace/projects/{$this->project->slug}/tasks/{$this->task->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Tasks/Show')->where('code', null));

    $repo = codeViewRepository(codeViewGit($this->organization), $this->project, 'app', 501);
    codeViewLink(codeViewPullRequest($repo, 4, GitPullRequest::STATE_OPEN, '2026-10-20 08:00:00'), $this->task);

    $this->actingAs($this->member)->get("/workspace/projects/{$this->project->slug}/tasks/{$this->task->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('code.branch_name', 'tp-1-fix-login')
            ->where('code.pull_requests.0.repository', 'sdoc/app'));
});
