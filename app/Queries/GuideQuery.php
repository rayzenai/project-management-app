<?php

namespace App\Queries;

use App\Models\GitConnection;
use App\Models\Organization;
use App\Models\User;
use App\Support\Git\GitAccess;
use App\Support\WorkspaceAccess;

/**
 * What the Guide fills in for its reader, so its code walkthrough names the
 * real forge: the forges they may use (GitAccess, their own organization's
 * first), and their forge username once they have signed in there.
 */
final class GuideQuery
{
    /**
     * @return array{forges: list<array{organization: string, url: string, owner: string}>, gitUsername: string|null}
     */
    public function get(?User $user): array
    {
        if ($user === null) {
            return ['forges' => [], 'gitUsername' => null];
        }

        $organizations = GitAccess::organizationsFor($user);
        $own = WorkspaceAccess::memberFor($user)?->organization_id;
        $connections = GitConnection::query()
            ->whereIn('organization_id', $organizations->modelKeys())
            ->get(['organization_id', 'base_url', 'owner'])
            ->keyBy('organization_id');

        $forges = array_values($organizations
            ->filter(fn (Organization $organization): bool => $connections->has($organization->id))
            ->sortBy(fn (Organization $organization): int => $organization->id === $own ? 0 : 1)
            ->map(fn (Organization $organization): array => [
                'organization' => $organization->name,
                'url' => rtrim($connections[$organization->id]->base_url, '/'),
                'owner' => $connections[$organization->id]->owner,
            ])
            ->all());

        return ['forges' => $forges, 'gitUsername' => $user->git_username];
    }
}
