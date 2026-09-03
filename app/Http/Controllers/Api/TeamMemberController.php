<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreTeamMemberRequest;
use App\Http\Requests\UpdateTeamMemberRoleRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Models\Team;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * JSON sibling of Workspace\TeamMemberController — team-scoped roster management.
 * Authorization is per-team (canManageRosterOf via the FormRequest + the destroy
 * guard), so leaders touch only the teams they lead while super-admins manage all.
 */
class TeamMemberController extends Controller
{
    public function store(StoreTeamMemberRequest $request, Team $team): JsonResponse
    {
        $member = DB::transaction(function () use ($request, $team): Member {
            $memberId = $request->integer('member_id');

            if ($memberId) {
                $team->members()->syncWithoutDetaching([$memberId]);

                return Member::findOrFail($memberId);
            }

            $userId = null;

            if ($request->filled('password')) {
                $userId = User::create([
                    'name' => $request->validated('name'),
                    'email' => $request->validated('email'),
                    'password' => Hash::make($request->validated('password')),
                ])->getKey();
            }

            $member = Member::create([
                'user_id' => $userId,
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'title' => $request->validated('title'),
            ]);

            $team->members()->syncWithoutDetaching([$member->id]);

            return $member;
        });

        return response()->json([
            'message' => 'Member added to team.',
            'data' => new MemberResource($member),
        ], 201);
    }

    public function destroy(Request $request, Team $team, Member $member): JsonResponse
    {
        abort_unless(WorkspaceAccess::canManageRosterOf($request->user(), $team), 403);

        $team->members()->detach($member->id);

        return response()->json(['message' => 'Member removed from team.']);
    }

    public function updateRole(UpdateTeamMemberRoleRequest $request, Team $team, Member $member): JsonResponse
    {
        $team->members()->updateExistingPivot($member->id, ['role' => $request->validated('role')]);

        return response()->json(['message' => 'Team role updated.']);
    }
}
