<?php

namespace App\Queries;

use App\Models\GitCommit;
use App\Support\Git\Checks;
use Illuminate\Database\Query\JoinClause;

/**
 * The checks of each repository's main branch (its `protected_branch`,
 * which MainBranch protects): the commit the branch points at, as the tracker
 * last read it. Missing for a repository whose main branch the tracker
 * hasn't seen, or whose head commit it hasn't read yet. One query for any
 * number of repositories.
 */
final class MainChecksQuery
{
    /**
     * @param  list<int>  $repositoryIds
     * @return array<int, array{state: string, checks: list<array{name: string, state: string, url: string|null}>}> by repository id
     */
    public function get(array $repositoryIds): array
    {
        if ($repositoryIds === []) {
            return [];
        }

        $checks = [];

        GitCommit::query()
            ->join('git_branches', fn (JoinClause $join) => $join
                ->on('git_branches.git_repository_id', '=', 'git_commits.git_repository_id')
                ->on('git_branches.head_sha', '=', 'git_commits.sha'))
            ->join('git_repositories', fn (JoinClause $join) => $join
                ->on('git_repositories.id', '=', 'git_branches.git_repository_id')
                ->on('git_repositories.protected_branch', '=', 'git_branches.name'))
            ->whereIn('git_commits.git_repository_id', $repositoryIds)
            ->whereNotNull('git_commits.checks_state')
            ->get(['git_commits.git_repository_id', 'git_commits.checks_state', 'git_commits.checks'])
            ->each(function (GitCommit $commit) use (&$checks): void {
                $shape = Checks::shape($commit->checks_state, $commit->checks);

                if ($shape !== null) {
                    $checks[$commit->git_repository_id] = $shape;
                }
            });

        return $checks;
    }
}
