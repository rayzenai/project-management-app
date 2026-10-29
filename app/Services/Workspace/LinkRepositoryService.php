<?php

namespace App\Services\Workspace;

use App\Jobs\BackfillGitRepository;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Project;
use App\Services\Git\ForgejoClient;
use App\Services\Git\ForgejoException;
use App\Services\Git\ForgeShapes;
use App\Services\Git\RepositorySync;
use App\Support\Background;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Links a forge repository to a project. The forge is asked first: the
 * repository must be in the organization's forge organization and linked to
 * no project yet. Then the forge is pointed back here (best effort) and the
 * backfill is queued.
 */
class LinkRepositoryService
{
    public function __construct(private readonly RepositorySync $sync) {}

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
            // A savepoint, so a lost race leaves any surrounding transaction usable.
            $repo = DB::transaction(fn (): GitRepository => $project->repositories()->create([
                ...array_map(fn (?string $value): string => (string) $value, $attributes),
                'git_connection_id' => $connection->id,
                'forge_id' => $forgeId,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Someone linked it a moment ago.
            return $this->alreadyLinked($this->linkedAs($connection, $forgeId), $actor);
        }

        $message = "Linked {$repo->full_name}.";

        if (! $this->sync->linkBack($repo)) {
            $message .= " The forge link back couldn't be set; the nightly reconcile will retry.";
        }

        Background::dispatch(new BackfillGitRepository($repo));

        return ServiceResult::success($repo, $message);
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
        if ($e->status === 404) {
            return $this->notInOrganization($connection);
        }

        if ($e->status === 0) {
            Log::warning('Forge unreachable.', ['url' => $connection->base_url, 'cause' => $e->causeMessage($connection->token)]);
        }

        return $e->status === 0 || $e->status >= 500
            ? ServiceResult::failure("Couldn't reach the forge at {$connection->base_url}.", 422)
            : ServiceResult::failure("The forge answered {$e->status}. Try again shortly.", 422);
    }
}
