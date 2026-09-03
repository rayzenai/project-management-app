<?php

namespace App\Support;

use App\Models\Member;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * The single source of truth for workspace authorization. Super-admin is the
 * config-driven `manage-workspace` Gate; team-leadership is the `role` column
 * on the member_team pivot. Every controller, form request, and resource that
 * makes a role decision goes through here.
 */
class WorkspaceAccess
{
    /**
     * Memoizes by the user instance so a project-list serialization that asks
     * `canArchiveProject` per project does not re-query leadership each time.
     * A WeakMap keeps this request-scoped — entries vanish with the user object,
     * so there is no cross-request or stale-id leakage.
     *
     * @var \WeakMap<Authenticatable, list<int>>
     */
    private static ?\WeakMap $ledTeamIdsMemo = null;

    public static function isSuperAdmin(?Authenticatable $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows('manage-workspace');
    }

    public static function leadsTeam(?Authenticatable $user, Team $team): bool
    {
        return in_array($team->getKey(), self::ledTeamIds($user), true);
    }

    public static function canManageRosterOf(?Authenticatable $user, Team $team): bool
    {
        return self::isSuperAdmin($user) || self::leadsTeam($user, $team);
    }

    public static function canViewProject(?Authenticatable $user, Project $project): bool
    {
        if ($project->is_public) {
            return true;
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        $memberId = Member::query()->where('user_id', $user->getAuthIdentifier())->value('id');

        return $memberId !== null
            && $project->teams()->whereHas('members', fn ($m) => $m->where('members.id', $memberId))->exists();
    }

    public static function canCreateProject(?Authenticatable $user): bool
    {
        return self::isSuperAdmin($user) || self::ledTeamIds($user) !== [];
    }

    public static function canManageProjectAccess(?Authenticatable $user, Project $project): bool
    {
        if (self::isSuperAdmin($user)) {
            return true;
        }

        $ledTeamIds = self::ledTeamIds($user);

        return $ledTeamIds !== []
            && $project->teams()->whereIn('teams.id', $ledTeamIds)->exists();
    }

    public static function canArchiveProject(?Authenticatable $user, Project $project): bool
    {
        return self::canManageProjectAccess($user, $project);
    }

    /**
     * True when the user may create a member and attach it to the given teams:
     * super-admins anywhere, leaders only for teams they all lead.
     *
     * Non-super-admins cannot create a member with NO team attachments — an
     * empty `$teamIds` returns false for leaders. Only super-admins may create
     * unattached members.
     *
     * @param  list<int>  $teamIds
     */
    public static function canCreateMemberForTeams(?Authenticatable $user, array $teamIds): bool
    {
        if (self::isSuperAdmin($user)) {
            return true;
        }

        if ($teamIds === []) {
            return false;
        }

        $ledTeamIds = self::ledTeamIds($user);

        foreach ($teamIds as $teamId) {
            if (! in_array((int) $teamId, $ledTeamIds, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when the user may edit this member's attributes (name, email,
     * password, active flag) — NOT whether they may change the member's team
     * affiliations. Returns true if the member shares ANY team the user leads.
     *
     * A non-super-admin may never manage a member whose linked login is itself
     * a super-admin — this blocks the attach-then-password-reset takeover vector
     * even when a super-admin legitimately shares a team with the leader.
     *
     * Team roster add/remove decisions must go through
     * `canManageRosterOf($user, $team)` for the specific team.
     */
    public static function canManageMember(?Authenticatable $user, Member $member): bool
    {
        if (self::isSuperAdmin($user)) {
            return true;
        }

        // A non-super-admin may never manage a member whose linked login is
        // itself a super-admin — blocks attach-then-password-reset takeover.
        if ($member->user_id !== null && $member->user !== null && self::isSuperAdmin($member->user)) {
            return false;
        }

        $ledTeamIds = self::ledTeamIds($user);

        return $ledTeamIds !== []
            && $member->teams()->whereIn('teams.id', $ledTeamIds)->exists();
    }

    /**
     * The team ids the user's linked member leads. Does not create a member as
     * a side effect (unlike Member::forUser), so it is safe in authorization.
     *
     * Memoized per user instance for the lifetime of the request to prevent
     * N+1 queries when called repeatedly (e.g. once per project in a list).
     *
     * @return list<int>
     */
    public static function ledTeamIds(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        self::$ledTeamIdsMemo ??= new \WeakMap;

        if (isset(self::$ledTeamIdsMemo[$user])) {
            return self::$ledTeamIdsMemo[$user];
        }

        $member = Member::query()->where('user_id', $user->getAuthIdentifier())->first();

        $ids = $member === null
            ? []
            : array_values($member->ledTeams()->pluck('teams.id')->map(fn ($id): int => (int) $id)->all());

        return self::$ledTeamIdsMemo[$user] = $ids;
    }
}
