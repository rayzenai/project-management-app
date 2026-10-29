<?php

namespace App\Support\Git;

use App\Models\Organization;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who may use git: the one rule behind the forge sign-in gate
 * (EnsureCanUseGit) and the `git_orgs` claim. Only organizations with a git
 * connection count:
 *
 * - a super-admin: every one of them;
 * - an active member: their own organization, plus the organization of every
 *   project whose team they are on (so a cross-organization team's outsiders
 *   get that project's office);
 * - anyone else (no member, or deactivated): none.
 */
class GitAccess
{
    /**
     * @return Collection<int, Organization> each with its gitConnection's owner loaded (never its token)
     */
    public static function organizationsFor(User $user): Collection
    {
        return self::query($user)?->with('gitConnection:id,organization_id,owner')->orderBy('id')->get() ?? new Collection;
    }

    /**
     * Whether organizationsFor lists any: the user may use git somewhere
     * (`gitEnabled` on the web, `git_enabled` on the API).
     */
    public static function anyFor(User $user): bool
    {
        return self::query($user)?->exists() ?? false;
    }

    /**
     * @return Builder<Organization>|null null when the user gets none
     */
    private static function query(User $user): ?Builder
    {
        $query = Organization::query()->whereHas('gitConnection');

        if (WorkspaceAccess::isSuperAdmin($user)) {
            return $query;
        }

        $member = WorkspaceAccess::memberFor($user);

        if ($member === null || ! $member->is_active) {
            return null;
        }

        return $query->where(function (Builder $q) use ($member): void {
            $q->whereHas('projects.teams.members', fn (Builder $m) => $m->where('members.id', $member->getKey()));

            if ($member->organization_id !== null) {
                $q->orWhere('organizations.id', $member->organization_id);
            }
        });
    }
}
