<?php

namespace App\Services\Git;

use App\Models\GitCommit;
use App\Models\GitPullRequest;
use App\Models\GitRepository;
use App\Support\Git\Checks;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Asks the forge for a commit's checks and keeps them on the commit and on
 * every pull request of its repository whose head it is. A finished
 * workflow run's webhook calls it at once; `git:commit-checks` asks again
 * for recent commits whose checks are unknown or still running, so a missed
 * webhook only delays the answer.
 */
class CommitChecks
{
    /**
     * @return string the commit's overall state (Checks)
     *
     * @throws ForgejoException
     */
    public function refresh(GitRepository $repository, string $sha): string
    {
        $combined = ForgejoClient::for($repository->gitConnection)->combinedStatus($repository->owner, $repository->name, $sha);
        ['state' => $state, 'checks' => $checks] = Checks::fromCombined($combined, $repository->gitConnection->base_url);

        $repository->commits()->where('sha', $sha)->get()->each(fn (GitCommit $commit) => $commit->forceFill([
            'checks_state' => $state,
            'checks' => $checks,
            'checks_checked_at' => now(),
        ])->save());

        $repository->pullRequests()->where('head_sha', $sha)->update([
            'checks_state' => $state,
            'checks' => json_encode($checks),
        ]);

        return $state;
    }

    /**
     * Gives open pull requests their head commit's checks where they differ.
     * A pull request delivery written while its head's checks were being
     * read copies nothing, and the read's update can't see its row yet.
     *
     * @return int how many pull requests it corrected
     */
    public function catchUpPullRequests(): int
    {
        $stale = DB::table('git_pull_requests')
            ->join('git_commits', fn (JoinClause $join) => $join
                ->on('git_commits.git_repository_id', '=', 'git_pull_requests.git_repository_id')
                ->on('git_commits.sha', '=', 'git_pull_requests.head_sha'))
            ->where('git_pull_requests.state', GitPullRequest::STATE_OPEN)
            ->whereNotNull('git_commits.checks_state')
            ->where(fn (Builder $q) => $q->whereNull('git_pull_requests.checks_state')
                ->orWhereColumn('git_pull_requests.checks_state', '!=', 'git_commits.checks_state'))
            ->get(['git_pull_requests.id', 'git_commits.checks_state', 'git_commits.checks']);

        foreach ($stale as $row) {
            GitPullRequest::query()->whereKey($row->id)->update(['checks_state' => $row->checks_state, 'checks' => $row->checks]);
        }

        return $stale->count();
    }
}
