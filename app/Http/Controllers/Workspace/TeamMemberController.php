<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Requests\SearchTeamCandidatesRequest;
use App\Http\Requests\StoreTeamMemberRequest;
use App\Http\Requests\UpdateTeamMemberRoleRequest;
use App\Models\Member;
use App\Models\Team;
use App\Queries\TeamCandidatesQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Team-scoped roster management, the surface team leaders operate through:
 * search for a person, add them, take them off. Leaders are chosen by the
 * office admin (or a super-admin), never by other leaders.
 */
class TeamMemberController extends Controller
{
    public function candidates(SearchTeamCandidatesRequest $request, Team $team, TeamCandidatesQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get($team, (string) $request->validated('q'))]);
    }

    public function store(StoreTeamMemberRequest $request, Team $team): RedirectResponse
    {
        $team->members()->syncWithoutDetaching([$request->integer('member_id')]);

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Added to '.$team->name.'.']);
    }

    public function destroy(Request $request, Team $team, Member $member): RedirectResponse
    {
        abort_unless(WorkspaceAccess::canRemoveFromTeam($request->user(), $team, $member), 403);

        $team->members()->detach($member->id);

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Removed from '.$team->name.'.']);
    }

    public function updateRole(UpdateTeamMemberRoleRequest $request, Team $team, Member $member): RedirectResponse
    {
        $team->members()->updateExistingPivot($member->id, ['role' => $request->validated('role')]);

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Team role updated.']);
    }
}
