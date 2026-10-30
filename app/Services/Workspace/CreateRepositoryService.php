<?php

namespace App\Services\Workspace;

use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Project;
use App\Models\User;
use App\Services\Git\ForgejoClient;
use App\Services\Git\ForgejoException;
use App\Services\Workspace\Concerns\WordsForgeFailures;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\Log;

/**
 * Makes a repository in the organization's forge organization (private,
 * empty, `main` as its default branch) with the bot's token, protects `main`
 * and links it to the project through LinkRepositoryService, whose access
 * sync then puts the maintainers on main's push allow-list. A repository
 * whose `main` couldn't be protected is still linked, and the message says
 * to protect it on the forge.
 */
class CreateRepositoryService
{
    use WordsForgeFailures;

    /**
     * Changes reach `main` through a pull request with one approval; only
     * the forge organization's Owners and the allow-listed users (the
     * maintainers, written by the access sync) push to it.
     */
    public const MAIN_RULE = [
        'rule_name' => 'main',
        'branch_name' => 'main',
        'enable_push' => true,
        'enable_push_whitelist' => true,
        'push_whitelist_usernames' => [],
        'push_whitelist_teams' => ['Owners'],
        'required_approvals' => 1,
        'dismiss_stale_approvals' => true,
        'block_on_rejected_reviews' => true,
    ];

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
            return ServiceResult::success($repository, "Created and linked {$owner}/{$name}, but main couldn't be protected: protect it on the forge.");
        }

        // Saved before the response: the access sync the link queued runs
        // after it and writes the maintainers onto the rule's allow-list.
        $repository->forceFill(['protects_main' => true])->save();

        return ServiceResult::success($repository, "Created and linked {$owner}/{$name}.");
    }

    private function protectMain(ForgejoClient $client, GitConnection $connection, string $name): bool
    {
        try {
            $client->protectBranch($connection->owner, $name, self::MAIN_RULE);

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
