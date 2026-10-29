<?php

namespace App\Services\Workspace;

use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Organization;
use App\Services\Git\ForgejoClient;
use App\Services\Git\ForgejoException;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\Log;

/**
 * Turns git off: deletes the forge webhook, points each linked repository
 * back at the forge's own issue tracker (so `TP-12` there stops leading
 * here), then deletes the connection and, with it, every link. The forge
 * calls are best effort: a forge that is down, or refuses one, never keeps
 * git on, and one found unreachable is not called again.
 */
class DisableGitService
{
    public function execute(Organization $organization): ServiceResult
    {
        $connection = $organization->gitConnection;

        if ($connection !== null) {
            $client = ForgejoClient::for($connection);

            if ($this->deleteHook($client, $connection)) {
                $this->restoreIssueTrackers($client, $connection);
            }

            $connection->delete();
        }

        return ServiceResult::success(null, 'Git is off.');
    }

    /**
     * @return bool false when the forge could not be reached
     */
    private function deleteHook(ForgejoClient $client, GitConnection $connection): bool
    {
        if ($connection->webhook_id === null) {
            return true;
        }

        try {
            $client->deleteOrganizationHook($connection->owner, $connection->webhook_id);
        } catch (ForgejoException $e) {
            Log::warning('Could not delete the forge webhook.', ['status' => $e->status]);

            return $e->status !== 0;
        }

        return true;
    }

    private function restoreIssueTrackers(ForgejoClient $client, GitConnection $connection): void
    {
        foreach ($connection->repositories()->orderBy('id')->get(['id', 'owner', 'name', 'full_name']) as $repository) {
            if (! $this->restoreIssueTracker($client, $repository)) {
                return;
            }
        }
    }

    /**
     * Points one repository back at the forge's own issue tracker. Best
     * effort: a refusal is logged (repository and status, never a token) and
     * never stops the caller.
     *
     * @return bool false when the forge could not be reached, so the caller can stop calling it
     */
    public function restoreIssueTracker(ForgejoClient $client, GitRepository $repository): bool
    {
        try {
            $client->useInternalTracker($repository->owner, $repository->name);
        } catch (ForgejoException $e) {
            Log::warning("Couldn't point a repository back at the forge's own issue tracker.", ['repository' => $repository->full_name, 'status' => $e->status]);

            return $e->status !== 0;
        }

        return true;
    }
}
