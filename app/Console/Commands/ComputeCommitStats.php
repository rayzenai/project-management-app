<?php

namespace App\Console\Commands;

use App\Models\GitCommit;
use App\Models\GitRepository;
use App\Models\User;
use App\Services\Git\CommitStats;
use App\Services\Git\ForgejoException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Counts the lines of commits that have no counts yet, oldest first, a few
 * hundred per run (the schedule runs it every five minutes). It also fills
 * `user_id` on commits ingested before their author had a forge username.
 *
 * A forge that can't be reached or answers 5xx is left alone for the rest of
 * the run, its commits waiting for the next; other connections go on.
 */
class ComputeCommitStats extends Command
{
    private const PAGE = 100;

    /** Consecutive 5xx answers after which a connection is left alone for the run. */
    private const SERVER_ERROR_STREAK = 5;

    protected $signature = 'git:commit-stats {--limit=300 : The most commits to count in this run}';

    protected $description = "Count each commit's code, comment and blank lines from its diff";

    public function handle(CommitStats $stats): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $this->fillUserIds();

        $counted = 0;
        $attempts = 0;
        /** @var array<int, true> $stopped connection ids not to ask again this run */
        $stopped = [];
        /** @var array<int, int> $serverErrors consecutive 5xx answers per connection id */
        $serverErrors = [];
        /** @var list<int> $skipped commits left for the next run (never more than the limit) */
        $skipped = [];

        while ($attempts < $limit) {
            $commits = $this->pending($stopped, $skipped, min(self::PAGE, $limit - $attempts));

            if ($commits->isEmpty()) {
                break;
            }

            foreach ($commits as $commit) {
                $repo = $commit->repository;
                $connectionId = $repo->git_connection_id;

                if (isset($stopped[$connectionId])) {
                    continue;
                }

                if ($attempts >= $limit) {
                    break;
                }

                $attempts++;

                try {
                    $stats->compute($commit);
                    $counted++;
                    $serverErrors[$connectionId] = 0;
                } catch (ForgejoException $e) {
                    $this->logFailure($repo, $commit, $e);

                    // Fails for every commit: unreachable, or refused or throttled.
                    if (in_array($e->status, [0, 401, 403, 429], true)) {
                        $stopped[$connectionId] = true;

                        continue;
                    }

                    $skipped[] = $commit->id;
                    $serverErrors[$connectionId] = $e->status >= 500 ? ($serverErrors[$connectionId] ?? 0) + 1 : 0;

                    if ($serverErrors[$connectionId] >= self::SERVER_ERROR_STREAK) {
                        $stopped[$connectionId] = true;
                    }
                }
            }
        }

        $left = $this->pendingQuery($stopped, [])->count();
        $this->line("Counted {$counted} commits.".($left > 0 && $attempts >= $limit ? " {$left} left for the next run." : ''));

        return self::SUCCESS;
    }

    /**
     * One update per user who matches, never one per commit.
     */
    private function fillUserIds(): void
    {
        $usernames = GitCommit::query()
            ->whereNull('user_id')
            ->whereNotNull('author_username')
            ->where('author_username', '!=', '')
            ->distinct()
            ->pluck('author_username')
            ->map(fn ($name) => strtolower((string) $name))
            ->unique()
            ->all();

        if ($usernames === []) {
            return;
        }

        User::query()
            ->whereNotNull('git_username')
            ->whereRaw('lower(git_username) in ('.implode(',', array_fill(0, count($usernames), '?')).')', $usernames)
            ->get(['id', 'git_username'])
            ->each(function (User $user): void {
                GitCommit::query()
                    ->whereNull('user_id')
                    ->whereRaw('lower(author_username) = ?', [strtolower((string) $user->git_username)])
                    ->update(['user_id' => $user->id]);
            });
    }

    /**
     * @param  array<int, true>  $stopped
     * @param  list<int>  $skipped
     * @return Collection<int, GitCommit>
     */
    private function pending(array $stopped, array $skipped, int $take): Collection
    {
        return $this->pendingQuery($stopped, $skipped)
            ->with('repository.gitConnection')
            ->orderBy('committed_at')
            ->orderBy('id')
            ->limit($take)
            ->get();
    }

    /**
     * @param  array<int, true>  $stopped
     * @param  list<int>  $skipped
     * @return Builder<GitCommit>
     */
    private function pendingQuery(array $stopped, array $skipped): Builder
    {
        return GitCommit::query()
            ->whereNull('stats_computed_at')
            ->when($skipped !== [], fn ($q) => $q->whereNotIn('id', $skipped))
            ->when($stopped !== [], fn ($q) => $q->whereNotIn(
                'git_repository_id',
                GitRepository::query()->whereIn('git_connection_id', array_keys($stopped))->select('id'),
            ));
    }

    private function logFailure(GitRepository $repo, GitCommit $commit, ForgejoException $e): void
    {
        $context = ['repository' => $repo->full_name, 'commit' => $commit->id, 'status' => $e->status];

        if ($e->status === 0) {
            $context['url'] = $repo->gitConnection->base_url;
            $context['cause'] = $e->causeMessage((string) $repo->gitConnection->token);
        }

        Log::warning('Counting a commit failed.', $context);
    }
}
