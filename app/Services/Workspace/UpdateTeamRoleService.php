<?php

namespace App\Services\Workspace;

use App\Models\Member;
use App\Models\Team;
use App\Support\ServiceResult;

/**
 * Sets a person's role on a project's team. Making someone the leader makes
 * them the project's manager; making the manager a member leaves the project
 * with none until someone is chosen (who may is the request's check,
 * WorkspaceAccess::canRemoveManager).
 */
class UpdateTeamRoleService
{
    public function __construct(private AppointProjectManagerService $appoint) {}

    public function execute(Team $team, Member $member, string $role): ServiceResult
    {
        if ($role === 'leader') {
            return $this->appoint->execute($team, $member);
        }

        if ($team->leaders()->whereKey($member->id)->exists()) {
            $team->members()->updateExistingPivot($member->id, ['role' => 'member']);

            return ServiceResult::success($member, $member->name.' no longer manages '.$team->name.'.');
        }

        return ServiceResult::success($member, $member->name.' is a member of '.$team->name.'.');
    }
}
