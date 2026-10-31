<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The one organization switch of a super-admin's workspace: which
 * organization's projects the sidebar lists, and which organization the
 * office pages and reports open on (OrganizationChoicesQuery::defaultId)
 * when the address names none. Null is all organizations, everyone's
 * starting point. Only a super-admin switches; anyone else's workspace is
 * their own organization's, so a value left on their row is ignored.
 *
 * Set by the sidebar's switch (OrganizationSwitchController) and by picking
 * an organization on any page (RemembersOrganizationChoice), so the two
 * never disagree.
 */
final class WorkspaceOrganization
{
    public static function chosen(?Authenticatable $user): ?int
    {
        if (! $user instanceof User || ! WorkspaceAccess::isSuperAdmin($user) || $user->workspace_organization_id === null) {
            return null;
        }

        return (int) $user->workspace_organization_id;
    }

    /**
     * Writes only a change.
     */
    public static function choose(User $user, ?int $organizationId): void
    {
        if ($user->workspace_organization_id === $organizationId) {
            return;
        }

        $user->forceFill(['workspace_organization_id' => $organizationId])->save();
    }
}
