<?php

namespace App\Jobs;

use App\Models\GitRepository;
use App\Models\Project;
use App\Services\Git\ForgejoException;
use App\Services\Git\RepositoryAccess;
use App\Support\Background;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes one linked repository's access to the forge
 * (RepositoryAccess::sync) once something changed who should have it. It
 * runs off the request: a forge failure is logged without the token and the
 * ten-minute `git:sync-access` tries again.
 *
 * Dispatched inside a transaction, it waits for the commit, so it reads what
 * was saved. When it runs it reads its repository afresh from the database
 * and syncs that; a repository unlinked since, or whose link never
 * committed, is skipped without a forge call.
 */
class SyncRepositoryAccess implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public GitRepository $repository)
    {
        $this->afterCommit = true;
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
        $repository = GitRepository::query()->with('gitConnection')->find($this->repository->id);

        if ($repository === null) {
            return;
        }

        try {
            $access->sync($repository);
        } catch (ForgejoException $e) {
            RepositoryAccess::logFailure($repository, $e);
        }
    }
}
