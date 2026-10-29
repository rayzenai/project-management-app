<?php

namespace App\Jobs;

use App\Models\GitRepository;
use App\Services\Git\RepositorySync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fills a newly linked repository's recent history in from the forge: its
 * open and recent pull requests, branches and the last 90 days of its
 * default branch, linked to tasks without changing any. A repository
 * unlinked before the job runs is skipped.
 */
class BackfillGitRepository implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Seconds. Paging a busy repository's history can outlast a worker's
     * default 60; the worker's --timeout and the queue's retry_after must be
     * above it (docs/forgejo.md, Queue worker).
     */
    public int $timeout = 300;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public GitRepository $repository) {}

    public function handle(RepositorySync $sync): void
    {
        if (! GitRepository::query()->whereKey($this->repository->getKey())->exists()) {
            return;
        }

        $sync->catchUp($this->repository, null);
    }
}
