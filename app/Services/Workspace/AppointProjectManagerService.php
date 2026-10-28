<?php

namespace App\Services\Workspace;

use App\Models\Member;
use App\Models\Team;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Makes a person the manager of a project: the one leader of its team. The
 * previous manager stays on the team as a member, and someone not yet on
 * the team joins it. Whether the person may join is the caller's check
 * ({@see WorkspaceAccess::canJoinTeam()}).
 */
class AppointProjectManagerService
{
    public function execute(Team $team, Member $member): ServiceResult
    {
        try {
            DB::transaction(function () use ($team, $member): void {
                // Step down first: a team holds one leader at a time.
                foreach ($team->leaders()->whereKeyNot($member->id)->pluck('members.id') as $previousId) {
                    $team->members()->updateExistingPivot($previousId, ['role' => 'member']);
                }

                if ($team->members()->whereKey($member->id)->exists()) {
                    $team->members()->updateExistingPivot($member->id, ['role' => 'leader']);
                } else {
                    $team->members()->attach($member->id, ['role' => 'leader']);
                }
            });

            return ServiceResult::success($member, $member->name.' now manages '.$team->name.'.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
