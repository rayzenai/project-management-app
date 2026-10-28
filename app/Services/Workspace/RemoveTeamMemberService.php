<?php

namespace App\Services\Workspace;

use App\Models\Member;
use App\Models\Team;
use App\Support\ServiceResult;

/**
 * Takes a person off a project's team. The manager stays until someone else
 * is made manager, so a project never loses its manager by accident.
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

        $team->members()->detach($member->id);

        return ServiceResult::success($member, 'Removed from '.$team->name.'.');
    }
}
