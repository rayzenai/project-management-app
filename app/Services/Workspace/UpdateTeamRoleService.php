<?php

namespace App\Services\Workspace;

use App\Models\Member;
use App\Models\Team;
use App\Support\ServiceResult;

/**
 * Sets a person's role on a project's team. Making someone the leader makes
 * them the project's manager; the manager cannot simply step down, because
 * a project always keeps one: someone else is made manager instead.
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
            return ServiceResult::failure(
                'A project always has a manager. Make someone else its manager instead.',
                422,
                ['role' => 'A project always has a manager. Make someone else its manager instead.'],
            );
        }

        return ServiceResult::success($member, $member->name.' is a member of '.$team->name.'.');
    }
}
