<?php

namespace App\Services\Git;

use App\Models\GitBranch;
use App\Models\GitCommit;
use App\Models\GitPullRequest;
use App\Models\GitRepository;
use App\Models\Project;
use App\Models\Task;
use App\Support\Git\TaskReferences;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The one way code reaches tasks: webhooks, backfill and the reconcile all
 * upsert branches, commits and pull requests here, so every path links them
 * to tasks the same way. Keys resolve only within the repository's
 * organization. Nothing here changes a task.
 */
class GitIngest
{
    /**
     * The tasks the references name in the repository's organization (see
     * task()), each once.
     *
     * @param  list<string>  $refs  `KEY-N`, as TaskReferences returns them
     * @return Collection<int, Task>
     */
    public function tasksFor(GitRepository $repo, array $refs): Collection
    {
        $organizationId = $repo->gitConnection->organization_id;
        $tasks = [];

        foreach ($refs as $ref) {
            $task = $this->task($organizationId, $ref);

            if ($task !== null) {
                $tasks[$task->id] = $task;
            }
        }

        return new Collection(array_values($tasks));
    }

    /**
     * The task a reference names in an organization: the project whose
     * current key, or one of whose old keys, is the reference's key, and its
     * task with that number. Null for an unknown key or number, or a trashed
     * task.
     *
     * @param  string  $ref  `KEY-N`
     */
    public function task(int $organizationId, string $ref): ?Task
    {
        [$key, $number] = explode('-', strtoupper($ref), 2);

        $projectId = Project::query()
            ->where('organization_id', $organizationId)
            ->where(fn (Builder $q) => $q->where('key', $key)
                ->orWhereHas('keyAliases', fn (Builder $alias) => $alias->where('organization_id', $organizationId)->where('key', $key)))
            ->value('id');

        return $projectId === null
            ? null
            : Task::query()->where('project_id', $projectId)->where('number', (int) $number)->first();
    }

    /**
     * Links are only ever added: a commit's message never changes, and a
     * task trashed later keeps the commits that named it.
     *
     * @param  array{sha: string, message: string, author_name: string, author_username: ?string, url: string, committed_at: string}  $c
     */
    public function commit(GitRepository $repo, array $c): GitCommit
    {
        $commit = $repo->commits()->updateOrCreate(['sha' => $c['sha']], [
            'message' => $c['message'],
            'author_name' => $c['author_name'],
            'author_username' => $c['author_username'],
            'url' => $c['url'],
            'committed_at' => $this->time($c['committed_at']),
        ]);

        $this->link($repo, $commit, $this->tasksFor($repo, TaskReferences::inText($c['message'])), detaching: false);

        return $commit;
    }

    public function branch(GitRepository $repo, string $name, ?string $headSha): GitBranch
    {
        $branch = $repo->branches()->updateOrCreate(['name' => $name], ['head_sha' => $headSha]);

        $this->link($repo, $branch, $this->tasksFor($repo, TaskReferences::inBranch($name)), detaching: true);

        return $branch;
    }

    public function deleteBranch(GitRepository $repo, string $name): void
    {
        $branch = $repo->branches()->where('name', $name)->first();

        if ($branch === null) {
            return;
        }

        DB::transaction(function () use ($branch): void {
            $branch->tasks()->detach();
            $branch->delete();
        });
    }

    /**
     * The links follow the pull request: a key edited out of the title or
     * body drops its task, one edited in adds it. The pull request is saved
     * before its links change, so a merge goes on the tasks it was already
     * linked to (GitPullRequestObserver), and a task linked by the same
     * delivery gets the single row its first link writes, which says merged
     * (TaskGitLinkObserver).
     *
     * A snapshot older than the stored one (by the forge's `updated_at`: a
     * backfill or a retried delivery overtaken by a newer delivery) changes
     * nothing, links included. One as new or newer is written.
     *
     * @param  array{number: int, title: string, body: ?string, state: string, url: string, head_branch: string, author_username: ?string, opened_at: string, merged_at: ?string, closed_at: ?string, updated_at: ?string}  $pr
     */
    public function pullRequest(GitRepository $repo, array $pr): GitPullRequest
    {
        $updatedAt = $this->time($pr['updated_at']);
        $stored = $repo->pullRequests()->where('number', $pr['number'])->first();

        if ($stored?->forge_updated_at !== null && $updatedAt !== null && $updatedAt->lessThan($stored->forge_updated_at)) {
            return $stored;
        }

        $pullRequest = $repo->pullRequests()->updateOrCreate(['number' => $pr['number']], [
            'title' => $pr['title'],
            'body' => $pr['body'],
            'state' => $pr['state'],
            'url' => $pr['url'],
            'head_branch' => $pr['head_branch'],
            'author_username' => $pr['author_username'],
            'opened_at' => $this->time($pr['opened_at']),
            'merged_at' => $this->time($pr['merged_at']),
            'closed_at' => $this->time($pr['closed_at']),
            'forge_updated_at' => $updatedAt ?? $stored?->forge_updated_at,
        ]);

        $refs = array_values(array_unique([
            ...TaskReferences::inText($pr['title']),
            ...TaskReferences::inText((string) $pr['body']),
            ...TaskReferences::inBranch($pr['head_branch']),
        ]));

        $this->link($repo, $pullRequest, $this->tasksFor($repo, $refs), detaching: true);

        return $pullRequest;
    }

    /**
     * Adds the links to the tasks not linked yet and, when detaching, drops
     * the ones to tasks no longer named. Two deliveries of the same commit or
     * pull request can add the same link at the same moment: the loser reads
     * the links again and adds what is still missing, once.
     *
     * @param  Collection<int, Task>  $tasks
     */
    private function link(GitRepository $repo, GitBranch|GitCommit|GitPullRequest $code, Collection $tasks, bool $detaching): void
    {
        $wanted = array_values($tasks->map(fn (Task $task): int => $task->id)->all());

        try {
            $current = $this->attachMissing($repo, $code, $wanted);
        } catch (UniqueConstraintViolationException) {
            $current = $this->attachMissing($repo, $code, $wanted);
        }

        $stale = array_values(array_diff($current, $wanted));

        if ($detaching && $stale !== []) {
            $code->tasks()->detach($stale);
        }
    }

    /**
     * Every link names its repository, so links go when it goes.
     *
     * @param  list<int>  $wanted
     * @return list<int> the tasks linked before this call
     */
    private function attachMissing(GitRepository $repo, GitBranch|GitCommit|GitPullRequest $code, array $wanted): array
    {
        $current = array_values($code->tasks()->allRelatedIds()->map(fn (mixed $id): int => (int) $id)->all());
        $missing = array_diff($wanted, $current);

        if ($missing !== []) {
            // A savepoint, so a lost race leaves any surrounding transaction usable for the retry.
            DB::transaction(fn () => $code->tasks()->attach(array_fill_keys($missing, ['git_repository_id' => $repo->id])));
        }

        return $current;
    }

    /**
     * Forge times carry their offset; the columns hold app-timezone wall
     * clock, so convert before the cast drops the offset.
     */
    private function time(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
    }
}
