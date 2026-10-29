<?php

namespace App\Queries;

use App\Http\Resources\GitPullRequestResource;
use App\Models\GitBranch;
use App\Models\GitCommit;
use App\Models\GitPullRequest;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A task's Code section: the branch name to use for it, and the pull
 * requests, branches and latest commits that mention it, each linking to the
 * forge. Callers decide whether the viewer may see code at all.
 *
 * A key resolves anywhere in the repository's organization, so a repository
 * linked to another project can mention this task. Its code shows only to
 * someone who may see that other project's code too.
 */
class TaskCodeQuery
{
    private const PULL_REQUESTS = 10;

    private const COMMITS = 10;

    /**
     * @return array{branch_name: string, pull_requests: list<array<string, mixed>>, branches: list<array<string, mixed>>, commits: list<array<string, mixed>>}
     */
    public function data(Task $task, ?Authenticatable $viewer): array
    {
        $task->loadMissing('project');

        $pullRequests = $task->pullRequests()->where(fn (Builder $code) => self::inVisibleRepositories($code, $viewer))
            ->with('repository')
            ->orderByRaw('CASE WHEN state = ? THEN 0 ELSE 1 END', [GitPullRequest::STATE_OPEN])
            ->orderByDesc('opened_at')
            ->orderByDesc('git_pull_requests.id')
            ->limit(self::PULL_REQUESTS)
            ->get();

        $branches = $task->branches()->where(fn (Builder $code) => self::inVisibleRepositories($code, $viewer))
            ->with('repository')
            ->orderBy('name')
            ->orderBy('git_branches.id')
            ->get()
            ->map(fn (GitBranch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'repository' => $branch->repository->full_name,
                'url' => $branch->repository->html_url.'/src/branch/'.implode('/', array_map(rawurlencode(...), explode('/', $branch->name))),
            ]);

        $commits = $task->commits()->where(fn (Builder $code) => self::inVisibleRepositories($code, $viewer))
            ->with('repository')
            ->orderByDesc('committed_at')
            ->orderByDesc('git_commits.id')
            ->limit(self::COMMITS)
            ->get()
            ->map(fn (GitCommit $commit): array => [
                'id' => $commit->id,
                'subject' => trim(Str::before($commit->message, "\n")),
                'short_sha' => substr($commit->sha, 0, 7),
                'url' => $commit->url,
                'author' => $commit->author_name,
                'repository' => $commit->repository->full_name,
                'committed_at' => $commit->committed_at->toIso8601String(),
                'unreachable' => $commit->unreachable_at !== null,
            ]);

        return [
            'branch_name' => $this->branchName($task),
            'pull_requests' => array_values(GitPullRequestResource::collection($pullRequests)->resolve()),
            'branches' => array_values($branches->all()),
            'commits' => array_values($commits->all()),
        ];
    }

    /**
     * Narrows branches, commits or pull requests to those of repositories
     * linked to a project whose code the viewer may see
     * (Project::scopeCodeVisibleTo).
     *
     * @template TCode of Model
     *
     * @param  Builder<TCode>  $code
     */
    public static function inVisibleRepositories(Builder $code, ?Authenticatable $viewer): void
    {
        $code->whereHas('repository', fn (Builder $repository) => $repository->whereIn(
            'project_id',
            Project::query()->codeVisibleTo($viewer)->select('projects.id'),
        ));
    }

    /**
     * The branch to start for the task: its code, then the start of its
     * title (tp-12-fix-login), which links the branch to the task.
     */
    private function branchName(Task $task): string
    {
        $code = strtolower($task->project->code.'-'.$task->number);

        return rtrim($code.'-'.Str::slug(Str::limit($task->title, 40, '')), '-');
    }
}
