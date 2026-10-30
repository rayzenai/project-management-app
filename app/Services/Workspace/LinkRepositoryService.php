<?php

namespace App\Services\Workspace;

use App\Jobs\BackfillGitRepository;
use App\Jobs\SyncRepositoryAccess;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Project;
use App\Models\User;
use App\Services\Git\ForgejoClient;
use App\Services\Git\ForgejoException;
use App\Services\Git\ForgeShapes;
use App\Services\Git\RepositoryAccess;
use App\Services\Git\RepositorySync;
use App\Services\Workspace\Concerns\WordsForgeFailures;
use App\Support\Background;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Links a forge repository to a project. The forge is asked first: the
 * repository must be in the organization's forge organization and linked to
 * no project yet. The link and the grants for the people the repository
 * already has are saved together: when the forge can't say who they are,
 * nothing is linked, so linking never takes anyone's access away. Then the
 * forge is pointed back here (best effort), and the access sync and the
 * backfill are queued.
 */
class LinkRepositoryService
{
    use WordsForgeFailures;

    public function __construct(
        private readonly RepositorySync $sync,
        private readonly RepositoryAccess $access,
    ) {}

    public function execute(Project $project, string $fullName, ?Authenticatable $actor): ServiceResult
    {
        $connection = GitConnection::query()->where('organization_id', $project->organization_id)->first();

        if ($connection === null) {
            return ServiceResult::failure("Git isn't on for this organization.", 422);
        }

        [$owner, $name] = explode('/', $fullName, 2);

        try {
            $forge = ForgejoClient::for($connection)->repository($owner, $name);
        } catch (ForgejoException $e) {
            return $this->forgeFailed($e, $connection);
        }

        $attributes = ForgeShapes::repository($forge);
        $forgeId = (int) ($forge['id'] ?? 0);

        // The forge follows renames and transfers: its answer, not the name asked for, decides.
        if ($forgeId <= 0 || mb_strtolower((string) $attributes['owner']) !== mb_strtolower($connection->owner)) {
            return $this->notInOrganization($connection);
        }

        $existing = $this->linkedAs($connection, $forgeId);
        if ($existing !== null) {
            return $this->alreadyLinked($existing, $actor);
        }

        try {
            // A savepoint, so a lost race or a failed import leaves any surrounding transaction usable.
            $repo = DB::transaction(function () use ($project, $attributes, $connection, $forgeId, $actor): GitRepository {
                $repo = $project->repositories()->create([
                    ...array_map(fn (?string $value): string => (string) $value, $attributes),
                    'git_connection_id' => $connection->id,
                    'forge_id' => $forgeId,
                ]);
                $this->access->importExisting($repo, $actor instanceof User ? $actor : null);

                return $repo;
            });
        } catch (UniqueConstraintViolationException) {
            // Someone linked it a moment ago.
            return $this->alreadyLinked($this->linkedAs($connection, $forgeId), $actor);
        } catch (ForgejoException $e) {
            $context = ['owner' => (string) $attributes['owner'], 'repository' => (string) $attributes['name'], 'status' => $e->status];

            if ($e->status === 0) {
                $context['url'] = $connection->base_url;
                $context['cause'] = $e->causeMessage($connection->token);
            }

            Log::warning("Couldn't import a forge repository's collaborators.", $context);

            return $this->importFailed((string) $attributes['full_name']);
        } catch (Throwable $e) {
            // Never report the throwable itself: it can carry a forge request and its token.
            report(new ForgejoException("Importing a forge repository's collaborators failed unexpectedly: ".class_basename($e).'.'));

            return $this->importFailed((string) $attributes['full_name']);
        }

        $message = "Linked {$repo->full_name}.";

        if (! $this->sync->linkBack($repo)) {
            $message .= " The forge link back couldn't be set; the nightly reconcile will retry.";
        }

        Background::dispatch(new SyncRepositoryAccess($repo));
        Background::dispatch(new BackfillGitRepository($repo));

        return ServiceResult::success($repo, $message);
    }

    private function importFailed(string $fullName): ServiceResult
    {
        return ServiceResult::failure("Couldn't read {$fullName}'s collaborators on the forge, so it wasn't linked. Try again shortly.", 422);
    }

    private function linkedAs(GitConnection $connection, int $forgeId): ?GitRepository
    {
        return GitRepository::query()
            ->with('project')
            ->where('git_connection_id', $connection->id)
            ->where('forge_id', $forgeId)
            ->first();
    }

    /**
     * The project is named only to someone who can see it.
     */
    private function alreadyLinked(?GitRepository $existing, ?Authenticatable $actor): ServiceResult
    {
        $project = $existing !== null && WorkspaceAccess::canViewProject($actor, $existing->project)
            ? $existing->project->title
            : 'another project';

        return ServiceResult::failure("That repository is already linked to {$project}.", 422);
    }

    private function notInOrganization(GitConnection $connection): ServiceResult
    {
        return ServiceResult::failure("That repository isn't in the {$connection->owner} organization on the forge.", 422);
    }

    private function forgeFailed(ForgejoException $e, GitConnection $connection): ServiceResult
    {
        return $e->status === 404 ? $this->notInOrganization($connection) : $this->forgeFailure($e, $connection);
    }
}
