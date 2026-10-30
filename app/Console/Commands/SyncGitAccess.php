<?php

namespace App\Console\Commands;

use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Services\Git\ForgejoException;
use App\Services\Git\RepositoryAccess;
use Illuminate\Console\Command;

/**
 * Every ten minutes, writes each linked repository's access to the forge
 * (RepositoryAccess::sync): it picks up people whose first forge sign-in
 * came after their grant, and anything an event's job missed. One line per
 * repository and a non-zero exit when any failed. A failure is logged
 * without the token; an unreachable forge fails its connection's other
 * repositories without waiting for it again.
 */
class SyncGitAccess extends Command
{
    protected $signature = 'git:sync-access
        {--connection= : Only the repositories of the git connection with this id}';

    protected $description = "Make every linked repository's forge collaborators match the tracker";

    public function handle(RepositoryAccess $access): int
    {
        $only = $this->option('connection');

        if ($only !== null && ! GitConnection::query()->whereKey((int) $only)->exists()) {
            $this->error("No git connection {$only}.");

            return self::FAILURE;
        }

        $repositories = GitRepository::query()
            ->with('gitConnection')
            ->when($only !== null, fn ($q) => $q->where('git_connection_id', (int) $only))
            ->orderBy('id')
            ->get();

        $unreachable = [];
        $failed = false;

        foreach ($repositories as $repository) {
            if (isset($unreachable[$repository->git_connection_id])) {
                $this->error("{$repository->full_name}: failed (forge answered 0).");
                $failed = true;

                continue;
            }

            try {
                $result = $access->sync($repository);
            } catch (ForgejoException $e) {
                RepositoryAccess::logFailure($repository, $e);
                $this->error("{$repository->full_name}: failed (forge answered {$e->status}).");
                $failed = true;

                if ($e->status === 0) {
                    $unreachable[$repository->git_connection_id] = true;
                }

                continue;
            }

            $this->line("{$repository->full_name}: ".count($result['granted']).' with access, '.count($result['waiting']).' waiting.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
