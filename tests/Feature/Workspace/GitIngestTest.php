<?php

use App\Models\GitBranch;
use App\Models\GitCommit;
use App\Models\GitConnection;
use App\Models\GitPullRequest;
use App\Models\GitRepository;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectKeyAlias;
use App\Models\Task;
use App\Models\TaskGitLink;
use App\Services\Git\GitIngest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->organization = Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
    $this->connection = gitIngestConnection($this->organization, 'sdoc');
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Tax Portal', 'key' => 'TP']);
    $this->task = Task::factory()->for($this->project)->create();
    $this->repo = gitIngestRepository($this->connection, $this->project, 'app');
    $this->ingest = app(GitIngest::class);
});

function gitIngestConnection(Organization $organization, string $owner): GitConnection
{
    return GitConnection::query()->create([
        'organization_id' => $organization->id,
        'base_url' => 'https://git.example',
        'owner' => $owner,
        'token' => 'bot-token',
    ]);
}

function gitIngestRepository(GitConnection $connection, Project $project, string $name): GitRepository
{
    static $forgeId = 100;

    return GitRepository::query()->create([
        'git_connection_id' => $connection->id,
        'project_id' => $project->id,
        'forge_id' => ++$forgeId,
        'owner' => $connection->owner,
        'name' => $name,
        'full_name' => "{$connection->owner}/{$name}",
        'html_url' => "https://git.example/{$connection->owner}/{$name}",
        'clone_url' => "https://git.example/{$connection->owner}/{$name}.git",
        'ssh_url' => "git@git.example:{$connection->owner}/{$name}.git",
        'default_branch' => 'main',
    ]);
}

/**
 * @return array{sha: string, message: string, author_name: string, author_username: ?string, url: string, committed_at: string}
 */
function gitIngestCommit(string $message, string $sha = 'a1b2c3d4e5f60718293a4b5c6d7e8f9001122334'): array
{
    return [
        'sha' => $sha,
        'message' => $message,
        'author_name' => 'Bikash Dev',
        'author_username' => 'bikash',
        'url' => "https://git.example/sdoc/app/commit/{$sha}",
        'committed_at' => '2026-10-30T04:15:00Z',
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{number: int, title: string, body: ?string, state: string, url: string, head_branch: string, author_username: ?string, opened_at: string, merged_at: ?string, closed_at: ?string, updated_at: ?string}
 */
function gitIngestPullRequest(array $overrides = []): array
{
    return array_merge([
        'number' => 3,
        'title' => 'Title',
        'body' => null,
        'state' => 'open',
        'url' => 'https://git.example/sdoc/app/pulls/3',
        'head_branch' => 'work',
        'author_username' => 'bikash',
        'opened_at' => '2026-10-30T04:15:00Z',
        'merged_at' => null,
        'closed_at' => null,
        'updated_at' => null,
    ], $overrides);
}

/**
 * Simulates two deliveries of the same commit at the same moment: right
 * after GitIngest reads a commit's links, the rival delivery links the next
 * task in the list (outside any savepoint, as another connection would).
 *
 * @param  list<int>  $taskIds  one per read, in order
 */
function gitRivalLinks(GitRepository $repo, array $taskIds): void
{
    DB::listen(function (QueryExecuted $query) use ($repo, &$taskIds): void {
        if ($taskIds === [] || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'task_git_links')) {
            return;
        }

        DB::table('task_git_links')->insert([
            'task_id' => array_shift($taskIds),
            'git_repository_id' => $repo->id,
            'linkable_type' => 'git-commit',
            'linkable_id' => GitCommit::query()->latest('id')->value('id'),
            'created_at' => now(),
        ]);
    });
}

/**
 * @return list<string>
 */
function gitTaskEntries(Task $task): array
{
    return ProjectActivity::query()->where('task_id', $task->id)->where('subject_type', 'git-pull-request')->orderBy('id')->pluck('description')->all();
}

it('links a commit to the task its message names, in the repository\'s organization', function () {
    $commit = $this->ingest->commit($this->repo, gitIngestCommit('TP-1 fix the login'));

    expect($commit->sha)->toBe('a1b2c3d4e5f60718293a4b5c6d7e8f9001122334')
        ->and($commit->repository->is($this->repo))->toBeTrue()
        ->and($this->task->commits()->pluck('git_commits.id')->all())->toBe([$commit->id]);
});

it('keeps the 64-character hashes of SHA-256 repositories', function () {
    $sha = str_repeat('0123456789abcdef', 4);

    $commit = $this->ingest->commit($this->repo, gitIngestCommit('TP-1 fix', $sha));
    $branch = $this->ingest->branch($this->repo, 'tp-1-x', $sha);

    expect($commit->fresh()?->sha)->toBe($sha)
        ->and($branch->fresh()?->head_sha)->toBe($sha)
        ->and($this->repo->gitConnection->is($this->connection))->toBeTrue();
});

it('stores forge times in the app timezone', function () {
    $commit = $this->ingest->commit($this->repo, gitIngestCommit('TP-1 fix'));

    expect($commit->fresh()?->committed_at?->format('Y-m-d H:i'))->toBe('2026-10-30 10:00');
});

it('links through a key the project used to have', function () {
    ProjectKeyAlias::query()->create(['project_id' => $this->project->id, 'organization_id' => $this->organization->id, 'key' => 'OLD']);

    $commit = $this->ingest->commit($this->repo, gitIngestCommit('OLD-1 fix'));

    expect($this->task->commits()->pluck('git_commits.id')->all())->toBe([$commit->id]);
});

it('never links to a task of another organization, by key or by alias', function () {
    $other = Organization::factory()->create(['name' => 'OPMCM', 'slug' => 'opmcm']);
    $sameKey = Project::factory()->create(['organization_id' => $other->id, 'key' => 'TP']);
    $sameKeyTask = Task::factory()->for($sameKey)->create();
    $ownKey = Project::factory()->create(['organization_id' => $other->id, 'key' => 'OT']);
    $ownKeyTask = Task::factory()->for($ownKey)->create();
    ProjectKeyAlias::query()->create(['project_id' => $ownKey->id, 'organization_id' => $other->id, 'key' => 'AL']);

    $this->ingest->commit($this->repo, gitIngestCommit('TP-1 OT-1 AL-1'));

    expect(TaskGitLink::query()->pluck('task_id')->all())->toBe([$this->task->id])
        ->and($sameKeyTask->gitLinks()->count())->toBe(0)
        ->and($ownKeyTask->gitLinks()->count())->toBe(0);
});

it('does not find a key inside a longer word', function () {
    $this->ingest->commit($this->repo, gitIngestCommit('ABTP-1 abTP-1 fix'));

    expect(TaskGitLink::query()->count())->toBe(0);
});

it('does not link a trashed task, or a number no task has', function () {
    $this->task->delete();

    $this->ingest->commit($this->repo, gitIngestCommit('TP-1 TP-99'));

    expect(TaskGitLink::query()->count())->toBe(0)
        ->and(GitCommit::query()->count())->toBe(1);
});

it('ingests the same commit once', function () {
    $this->ingest->commit($this->repo, gitIngestCommit('TP-1 fix'));
    $this->ingest->commit($this->repo, gitIngestCommit('TP-1 fix'));

    expect(GitCommit::query()->count())->toBe(1)
        ->and(TaskGitLink::query()->count())->toBe(1);
});

it('keeps the link another delivery of the same commit made at the same moment', function () {
    gitRivalLinks($this->repo, [$this->task->id]);

    $this->ingest->commit($this->repo, gitIngestCommit('TP-1 fix'));

    expect(TaskGitLink::query()->pluck('task_id')->all())->toBe([$this->task->id]);
});

it('gives up after one retry when the link race is lost twice', function () {
    $second = Task::factory()->for($this->project)->create();
    gitRivalLinks($this->repo, [$this->task->id, $second->id]);

    expect(fn () => $this->ingest->commit($this->repo, gitIngestCommit('TP-1 TP-2 fix')))->toThrow(UniqueConstraintViolationException::class);
});

it('drops a pull request\'s link when its title loses the key, and adds one when it gains a key', function () {
    $second = Task::factory()->for($this->project)->create();

    $pr = $this->ingest->pullRequest($this->repo, gitIngestPullRequest(['title' => 'TP-1 login']));
    expect($this->task->pullRequests()->pluck('git_pull_requests.id')->all())->toBe([$pr->id]);

    $this->ingest->pullRequest($this->repo, gitIngestPullRequest(['title' => 'TP-2 login']));

    expect($this->task->pullRequests()->count())->toBe(0)
        ->and($second->pullRequests()->pluck('git_pull_requests.id')->all())->toBe([$pr->id])
        ->and($pr->fresh()?->title)->toBe('TP-2 login');
});

it('links a pull request through its body in upper case and its head branch in any case', function () {
    $second = Task::factory()->for($this->project)->create();
    $third = Task::factory()->for($this->project)->create();

    $pr = $this->ingest->pullRequest($this->repo, gitIngestPullRequest(['body' => 'Closes TP-2, not tp-3', 'head_branch' => 'tp-1-login']));

    expect(TaskGitLink::query()->orderBy('task_id')->pluck('task_id')->all())->toBe([$this->task->id, $second->id])
        ->and($third->pullRequests()->count())->toBe(0)
        ->and($pr->head_branch)->toBe('tp-1-login');
});

it('links a branch through its name', function () {
    $branch = $this->ingest->branch($this->repo, 'tp-1-x', 'a1b2c3d4e5f60718293a4b5c6d7e8f9001122334');

    expect($this->task->branches()->pluck('git_branches.id')->all())->toBe([$branch->id])
        ->and($branch->head_sha)->toBe('a1b2c3d4e5f60718293a4b5c6d7e8f9001122334');

    $moved = $this->ingest->branch($this->repo, 'tp-1-x', 'b1b2c3d4e5f60718293a4b5c6d7e8f9001122334');

    expect($moved->id)->toBe($branch->id)
        ->and($moved->head_sha)->toBe('b1b2c3d4e5f60718293a4b5c6d7e8f9001122334')
        ->and(TaskGitLink::query()->count())->toBe(1);
});

it('removes a deleted branch and its links', function () {
    $this->ingest->branch($this->repo, 'tp-1-x', null);

    $this->ingest->deleteBranch($this->repo, 'tp-1-x');
    $this->ingest->deleteBranch($this->repo, 'never-existed');

    expect(GitBranch::query()->count())->toBe(0)
        ->and(TaskGitLink::query()->count())->toBe(0);
});

it('records linking and unlinking a repository on the project', function () {
    $this->repo->delete();

    expect(ProjectActivity::query()->where('project_id', $this->project->id)->whereNull('task_id')->orderBy('id')->pluck('description')->all())
        ->toBe(['Linked repository sdoc/app', 'Unlinked repository sdoc/app']);
});

it('drops the task links of a repository deleted by a database cascade', function () {
    $this->ingest->commit($this->repo, gitIngestCommit('TP-1 fix'));
    $this->ingest->pullRequest($this->repo, gitIngestPullRequest(['title' => 'TP-1 login']));

    DB::table('git_connections')->where('id', $this->connection->id)->delete();

    expect(GitRepository::query()->count())->toBe(0)
        ->and(TaskGitLink::query()->count())->toBe(0);
});

it('drops the task links of an unlinked repository', function () {
    $this->ingest->commit($this->repo, gitIngestCommit('TP-1 fix'));
    $this->ingest->branch($this->repo, 'tp-1-x', null);
    $this->ingest->pullRequest($this->repo, gitIngestPullRequest(['title' => 'TP-1 login']));

    $this->repo->delete();

    expect(TaskGitLink::query()->count())->toBe(0);
});

it('records a pull request on a task when it is first linked', function () {
    $this->ingest->pullRequest($this->repo, gitIngestPullRequest(['head_branch' => 'tp-1-title']));
    $this->ingest->pullRequest($this->repo, gitIngestPullRequest(['head_branch' => 'tp-1-title', 'body' => 'Edited']));

    expect(gitTaskEntries($this->task))->toBe(['Pull request #3 opened: Title']);
});

it('records a merge on every linked task, once', function () {
    $second = Task::factory()->for($this->project)->create();
    $this->ingest->pullRequest($this->repo, gitIngestPullRequest(['body' => 'TP-1 TP-2']));

    $merged = gitIngestPullRequest(['body' => 'TP-1 TP-2', 'state' => 'merged', 'merged_at' => '2026-10-31T04:15:00Z', 'closed_at' => '2026-10-31T04:15:00Z']);
    $this->ingest->pullRequest($this->repo, $merged);
    $this->ingest->pullRequest($this->repo, $merged);

    expect(gitTaskEntries($this->task))->toBe(['Pull request #3 opened: Title', 'Pull request #3 merged'])
        ->and(gitTaskEntries($second))->toBe(['Pull request #3 opened: Title', 'Pull request #3 merged']);
});

it('describes a pull request first linked by the state it is in', function () {
    $second = Task::factory()->for($this->project)->create();

    $this->ingest->pullRequest($this->repo, gitIngestPullRequest(['body' => 'TP-1', 'state' => 'merged', 'merged_at' => '2026-10-31T04:15:00Z', 'closed_at' => '2026-10-31T04:15:00Z']));
    $this->ingest->pullRequest($this->repo, gitIngestPullRequest(['number' => 4, 'body' => 'TP-2', 'state' => 'closed', 'closed_at' => '2026-10-31T04:15:00Z']));

    expect(gitTaskEntries($this->task))->toBe(['Pull request #3 merged: Title'])
        ->and(gitTaskEntries($second))->toBe(['Pull request #4 closed: Title']);
});

it('records one row for a task whose key arrives with the merge', function () {
    $second = Task::factory()->for($this->project)->create();
    $this->ingest->pullRequest($this->repo, gitIngestPullRequest(['body' => 'TP-1']));

    $this->ingest->pullRequest($this->repo, gitIngestPullRequest(['body' => 'TP-1 TP-2', 'state' => 'merged', 'merged_at' => '2026-10-31T04:15:00Z', 'closed_at' => '2026-10-31T04:15:00Z']));

    expect(gitTaskEntries($this->task))->toBe(['Pull request #3 opened: Title', 'Pull request #3 merged'])
        ->and(gitTaskEntries($second))->toBe(['Pull request #3 merged: Title']);
});

it('ignores a pull request snapshot older than the stored one, links included', function () {
    $second = Task::factory()->for($this->project)->create();
    $merged = $this->ingest->pullRequest($this->repo, gitIngestPullRequest([
        'title' => 'TP-1 login', 'state' => 'merged', 'merged_at' => '2026-10-31T04:15:00Z', 'closed_at' => '2026-10-31T04:15:00Z', 'updated_at' => '2026-10-31T04:15:00Z',
    ]));
    $entries = gitTaskEntries($this->task);

    $returned = $this->ingest->pullRequest($this->repo, gitIngestPullRequest(['title' => 'TP-2 draft', 'state' => 'open', 'updated_at' => '2026-10-30T09:00:00Z']));

    $stored = $merged->fresh();
    expect($returned->id)->toBe($merged->id)
        ->and($stored?->only(['title', 'state']))->toBe(['title' => 'TP-1 login', 'state' => 'merged'])
        ->and($stored?->forge_updated_at?->equalTo(CarbonImmutable::parse('2026-10-31T04:15:00Z')))->toBeTrue()
        ->and($this->task->pullRequests()->count())->toBe(1)
        ->and($second->pullRequests()->count())->toBe(0)
        ->and(gitTaskEntries($this->task))->toBe($entries);
});

it('writes a pull request snapshot as new as the stored one, or newer', function () {
    $second = Task::factory()->for($this->project)->create();
    $this->ingest->pullRequest($this->repo, gitIngestPullRequest(['title' => 'TP-1 login', 'updated_at' => '2026-10-30T09:00:00Z']));

    $this->ingest->pullRequest($this->repo, gitIngestPullRequest(['title' => 'TP-1 login, edited', 'updated_at' => '2026-10-30T09:00:00Z']));
    expect(GitPullRequest::query()->sole()->title)->toBe('TP-1 login, edited');

    $this->ingest->pullRequest($this->repo, gitIngestPullRequest(['title' => 'TP-2 login', 'state' => 'merged', 'merged_at' => '2026-10-31T04:15:00Z', 'closed_at' => '2026-10-31T04:15:00Z', 'updated_at' => '2026-10-31T04:15:00Z']));

    $stored = GitPullRequest::query()->sole();
    expect($stored->only(['title', 'state']))->toBe(['title' => 'TP-2 login', 'state' => 'merged'])
        ->and($stored->forge_updated_at?->equalTo(CarbonImmutable::parse('2026-10-31T04:15:00Z')))->toBeTrue()
        ->and($this->task->pullRequests()->count())->toBe(0)
        ->and($second->pullRequests()->count())->toBe(1);
});

it('records nothing for commits and branches', function () {
    $before = ProjectActivity::query()->count();

    $this->ingest->commit($this->repo, gitIngestCommit('TP-1 fix'));
    $this->ingest->branch($this->repo, 'tp-1-x', null);

    expect(ProjectActivity::query()->count())->toBe($before)
        ->and(TaskGitLink::query()->count())->toBe(2);
});
