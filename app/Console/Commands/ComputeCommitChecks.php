<?php

namespace App\Console\Commands;

use App\Models\GitCommit;
use App\Models\GitRepository;
use App\Services\Git\CommitChecks;
use App\Services\Git\ForgejoException;
use App\Support\Git\Checks;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Asks the forge for the checks of commits the tracker learned of in the
 * last RECENT_DAYS whose checks it hasn't read yet or which still run,
 * newest first; a finished run's webhook normally answers sooner, so this
 * only covers a missed one. A commit with no checks is asked again while it
 * is younger than NONE_AGAIN_MINUTES, in case its run hadn't started. A
 * commit is asked again no sooner than AGAIN_AFTER_SECONDS. A forge that
 * can't be reached (0) or refuses or throttles (401, 403, 429) is left
 * alone for the rest of the run; any other failure skips only that commit
 * until next time.
 */
class ComputeCommitChecks extends Command
{
    private const RECENT_DAYS = 2;

    private const AGAIN_AFTER_SECONDS = 90;

    private const NONE_AGAIN_MINUTES = 10;

    protected $signature = 'git:commit-checks {--limit=200 : The most commits to ask about in this run}';

    protected $description = "Read recent commits' checks (Forgejo Actions results) from the forge";

    public function handle(CommitChecks $checks): int
    {
        $commits = GitCommit::query()
            ->with('repository.gitConnection')
            ->whereNull('unreachable_at')
            ->where('created_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->where(fn (Builder $q) => $q->whereNull('checks_state')
                ->orWhere('checks_state', Checks::RUNNING)
                ->orWhere(fn (Builder $q) => $q->where('checks_state', Checks::NONE)->where('created_at', '>=', now()->subMinutes(self::NONE_AGAIN_MINUTES))))
            ->where(fn (Builder $q) => $q->whereNull('checks_checked_at')->orWhere('checks_checked_at', '<', now()->subSeconds(self::AGAIN_AFTER_SECONDS)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        /** @var array<int, true> $stopped connection ids not to ask again this run */
        $stopped = [];
        $asked = 0;

        foreach ($commits as $commit) {
            $repository = $commit->repository;

            if (isset($stopped[$repository->git_connection_id])) {
                continue;
            }

            try {
                $checks->refresh($repository, $commit->sha);
                $asked++;
            } catch (ForgejoException $e) {
                $this->logFailure($repository, $commit, $e);

                if (in_array($e->status, [0, 401, 403, 429], true)) {
                    $stopped[$repository->git_connection_id] = true;
                } else {
                    $commit->forceFill(['checks_checked_at' => now()])->save();
                }
            }
        }

        $this->line("Read the checks of {$asked} commits.");

        return self::SUCCESS;
    }

    private function logFailure(GitRepository $repository, GitCommit $commit, ForgejoException $e): void
    {
        $context = ['repository' => $repository->full_name, 'commit' => $commit->id, 'status' => $e->status];

        if ($e->status === 0) {
            $context['url'] = $repository->gitConnection->base_url;
            $context['cause'] = $e->causeMessage((string) $repository->gitConnection->token);
        }

        Log::warning("Reading a commit's checks failed.", $context);
    }
}
