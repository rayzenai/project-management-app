<?php

namespace App\Services\Workspace;

use App\Models\Member;
use App\Models\Team;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;

/**
 * Puts a person on a project's team as a member (someone already on it
 * keeps their role): the People tab's add, and assigning someone not yet on
 * the team. TeamMembershipObserver logs it and tells them. Whether the user
 * may add this person is the caller's check
 * ({@see WorkspaceAccess::canManageRosterOf()} and canJoinTeam).
 */
class AddTeamMemberService
{
    public function execute(Team $team, Member $member): ServiceResult
    {
        $team->members()->syncWithoutDetaching([$member->id]);

        return ServiceResult::success($member, 'Added to '.$team->name.'.');
    }
}
