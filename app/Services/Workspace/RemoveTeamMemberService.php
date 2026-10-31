<?php

namespace App\Services\Workspace;

use App\Models\Member;
use App\Models\Team;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;

/**
 * Takes a person off a project's team, its manager too (the project then
 * has none until someone is chosen; who may is
 * WorkspaceAccess::canRemoveFromTeam). The removal and what it takes with it
 * (their grants on the project's repositories, TeamMembershipObserver)
 * happen together or not at all.
 */
class RemoveTeamMemberService
{
    public function execute(Team $team, Member $member): ServiceResult
    {
        DB::transaction(fn () => $team->members()->detach($member->id));

        return ServiceResult::success($member, 'Removed from '.$team->name.'.');
    }
}
