<?php

namespace App\Services\Git;

use App\Models\GitCommit;
use App\Models\GitRepository;
use App\Support\Git\Checks;

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
        ['state' => $state, 'checks' => $checks] = Checks::fromCombined($combined);

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
}
