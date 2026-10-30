<?php

namespace App\Jobs;

use App\Models\GitRepository;
use App\Models\Project;
use App\Services\Git\ForgejoException;
use App\Services\Git\RepositoryAccess;
use App\Support\Background;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes one linked repository's access to the forge
 * (RepositoryAccess::sync) once something changed who should have it. It
 * runs off the request: a forge failure is logged without the token and the
 * ten-minute `git:sync-access` tries again. A repository unlinked before it
 * runs is skipped.
 *
 * Dispatched inside a transaction, it waits for the commit, so it reads what
 * was saved. It is unique by repository until it starts: a second sync
 * dispatched while one waits is dropped (the waiting one reads the latest
 * state), and a change made while a sync runs still queues the next. Only a
 * dispatch that takes the lock is dropped (dispatch(), PendingDispatch);
 * Background::dispatch goes through Bus::dispatch, which takes none.
 */
class SyncRepositoryAccess implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /** Seconds the unique lock is held at most. */
    public int $uniqueFor = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public GitRepository $repository)
    {
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return (string) $this->repository->getKey();
    }

    /**
     * One sync per repository linked to the project.
     */
    public static function forProject(Project $project): void
    {
        foreach ($project->repositories()->orderBy('id')->get() as $repository) {
            Background::dispatch(new self($repository));
        }
    }

    public function handle(RepositoryAccess $access): void
    {
        try {
            $access->sync($this->repository);
        } catch (ForgejoException $e) {
            RepositoryAccess::logFailure($this->repository, $e);
        }
    }
}
