<?php

namespace App\Services\Workspace;

use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Project;
use App\Queries\UnlinkedRepositoriesQuery;
use App\Services\Git\ForgejoException;
use App\Services\Workspace\Concerns\WordsForgeFailures;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * "New project for it": a project made for a forge repository no project
 * links, and the repository linked to it, in one transaction. The forge is
 * asked first (never the cached list): a repository no longer waiting to be
 * linked is refused, and the cached list forgotten so its row goes. The
 * link must be of that very repository (a rename can hand its name to
 * another). The project is made by CreateProjectService in the
 * connection's organization, not public, managed by the caller when they
 * are an active person of that organization (a super-admin who isn't
 * leaves it without a manager, as Maintenance then says); the link is
 * LinkRepositoryService's, which queues the backfill of its history. When
 * the link fails, nothing is created and its message says why.
 */
class CreateProjectForRepositoryService
{
    use WordsForgeFailures;

    public const NOT_WAITING = 'That repository is no longer waiting to be linked.';

    public function __construct(
        private readonly UnlinkedRepositoriesQuery $unlinked,
        private readonly CreateProjectService $projects,
        private readonly LinkRepositoryService $link,
    ) {}

    /**
     * @param  array{title: string, key?: ?string, program_id?: ?int}  $attributes
     */
    public function execute(GitConnection $connection, int $forgeId, array $attributes, Authenticatable $actor): ServiceResult
    {
        try {
            $repository = $this->unlinked->find($connection, $forgeId);
        } catch (ForgejoException $e) {
            return $this->forgeFailure($e, $connection);
        }

        if ($repository === null) {
            return $this->notWaiting($connection);
        }

        $member = WorkspaceAccess::memberFor($actor);
        $managerId = $member !== null && $member->is_active && (int) $member->organization_id === (int) $connection->organization_id
            ? (int) $member->id
            : null;

        DB::beginTransaction();

        try {
            $created = $this->projects->execute([
                'title' => $attributes['title'],
                'key' => $attributes['key'] ?? null,
                'program_id' => $attributes['program_id'] ?? null,
                'organization_id' => (int) $connection->organization_id,
                'manager_id' => $managerId,
                'is_public' => false,
            ]);

            if (! $created->success || ! $created->data instanceof Project) {
                DB::rollBack();

                return $created;
            }

            $project = $created->data;
            $linked = $this->link->execute($project, $repository['full_name'], $actor);

            if (! $linked->success || ! $linked->data instanceof GitRepository) {
                DB::rollBack();

                return $linked;
            }

            // The link reads the repository by name: renamed meanwhile, the
            // name now belongs to another one, which is not the one asked for.
            if ($linked->data->forge_id !== $forgeId) {
                DB::rollBack();

                return $this->notWaiting($connection);
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            report($e);

            return ServiceResult::fromException($e);
        }

        // Anything the link adds (the forge's link back not set) stays said.
        $more = trim(Str::after((string) $linked->message, "Linked {$linked->data->full_name}."));

        return ServiceResult::success(
            ['project' => $project, 'repository' => $linked->data],
            trim("Created {$project->title} and linked {$linked->data->full_name}. Its earlier commits are being read now. {$more}"),
        );
    }

    /**
     * The cached list still offers it: read the forge again next time, so
     * the stale row goes.
     */
    private function notWaiting(GitConnection $connection): ServiceResult
    {
        UnlinkedRepositoriesQuery::forget((int) $connection->id);

        return ServiceResult::failure(self::NOT_WAITING, 422);
    }
}
