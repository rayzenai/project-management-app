<?php

namespace App\Services\Workspace;

use App\Models\Member;
use App\Models\Team;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;

/**
 * Takes a person off a project's team. The manager stays until someone else
 * is made manager, so a project never loses its manager by accident. The
 * removal and what it takes with it (their grants on the project's
 * repositories, TeamMembershipObserver) happen together or not at all.
 */
class RemoveTeamMemberService
{
    public function execute(Team $team, Member $member): ServiceResult
    {
        if ($team->leaders()->whereKey($member->id)->exists()) {
            return ServiceResult::failure(
                'Make someone else the manager before taking '.$member->name.' off the team.',
                422,
                ['member' => 'Make someone else the manager before taking '.$member->name.' off the team.'],
            );
        }

        DB::transaction(fn () => $team->members()->detach($member->id));

        return ServiceResult::success($member, 'Removed from '.$team->name.'.');
    }
}
