<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\SearchTeamCandidatesRequest;
use App\Http\Requests\StoreTeamMemberRequest;
use App\Http\Requests\UpdateTeamMemberRoleRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Models\Team;
use App\Queries\TeamCandidatesQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\TeamMemberController: search, add, remove, and
 * choose leaders. Authorization lives in the FormRequests and WorkspaceAccess.
 */
class TeamMemberController extends Controller
{
    public function candidates(SearchTeamCandidatesRequest $request, Team $team, TeamCandidatesQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get($team, (string) $request->validated('q'))]);
    }

    public function store(StoreTeamMemberRequest $request, Team $team): JsonResponse
    {
        $member = Member::query()->findOrFail($request->integer('member_id'));

        $team->members()->syncWithoutDetaching([$member->id]);

        return response()->json([
            'message' => 'Added to '.$team->name.'.',
            'data' => new MemberResource($member),
        ], 201);
    }

    public function destroy(Request $request, Team $team, Member $member): JsonResponse
    {
        abort_unless(WorkspaceAccess::canRemoveFromTeam($request->user(), $team, $member), 403);

        $team->members()->detach($member->id);

        return response()->json(['message' => 'Removed from '.$team->name.'.']);
    }

    public function updateRole(UpdateTeamMemberRoleRequest $request, Team $team, Member $member): JsonResponse
    {
        $team->members()->updateExistingPivot($member->id, ['role' => $request->validated('role')]);

        return response()->json(['message' => 'Team role updated.']);
    }
}
