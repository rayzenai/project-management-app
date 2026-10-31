<?php

namespace App\Services\Workspace;

use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Project;
use App\Models\User;
use App\Services\Git\ForgejoClient;
use App\Services\Git\ForgejoException;
use App\Services\Git\MainBranch;
use App\Services\Workspace\Concerns\WordsForgeFailures;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\Log;

/**
 * Makes a repository in the organization's forge organization (private,
 * `main` as its default branch, starting from a README) with the bot's
 * token, protects `main` with the tracker's rule (MainBranch) and links it
 * to the project through LinkRepositoryService. A repository whose `main`
 * couldn't be protected yet is still linked: the access sync keeps trying,
 * and Maintenance lists it until it is.
 */
class CreateRepositoryService
{
    use WordsForgeFailures;

    public function __construct(private readonly LinkRepositoryService $link) {}

    public function execute(Project $project, string $name, ?string $description, ?User $actor): ServiceResult
    {
        $connection = GitConnection::query()->where('organization_id', $project->organization_id)->first();

        if ($connection === null) {
            return ServiceResult::failure("Git isn't on for this organization.", 422);
        }

        $owner = $connection->owner;
        $client = ForgejoClient::for($connection);

        try {
            $client->createOrganizationRepository($owner, $name, $description);
        } catch (ForgejoException $e) {
            return $e->status === 409
                ? ServiceResult::failure("A repository named {$name} already exists in {$owner}. Link it instead.", 422)
                : $this->forgeFailure($e, $connection);
        }

        $protected = $this->protectMain($client, $connection, $name);
        $linked = $this->link->execute($project, "{$owner}/{$name}", $actor);

        if (! $linked->success || ! $linked->data instanceof GitRepository) {
            return ServiceResult::failure("Created {$owner}/{$name} on the forge, but couldn't link it: {$linked->message}", 422);
        }

        $repository = $linked->data;

        if (! $protected) {
            return ServiceResult::success($repository, "Created and linked {$owner}/{$name}, but main couldn't be protected yet; the tracker keeps trying.");
        }

        $repository->forceFill(['protects_main' => true])->save();

        return ServiceResult::success($repository, "Created and linked {$owner}/{$name}.");
    }

    private function protectMain(ForgejoClient $client, GitConnection $connection, string $name): bool
    {
        try {
            MainBranch::ensure($client, $connection->owner, $name);

            return true;
        } catch (ForgejoException $e) {
            $context = ['repository' => "{$connection->owner}/{$name}", 'status' => $e->status];

            if ($e->status === 0) {
                $context['url'] = $connection->base_url;
                $context['cause'] = $e->causeMessage($connection->token);
            }

            Log::warning("Couldn't protect a new forge repository's main branch.", $context);

            return false;
        }
    }
}
