<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Requests\StoreTeamMemberRequest;
use App\Http\Requests\UpdateTeamMemberRoleRequest;
use App\Models\Member;
use App\Models\Team;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Team-scoped roster management — the surface team leaders operate through.
 * Authorization is per-team (canManageRosterOf), so leaders touch only the
 * teams they lead while super-admins manage every team.
 */
class TeamMemberController extends Controller
{
    public function store(StoreTeamMemberRequest $request, Team $team): RedirectResponse
    {
        DB::transaction(function () use ($request, $team): void {
            $memberId = $request->integer('member_id');

            if ($memberId) {
                $team->members()->syncWithoutDetaching([$memberId]);

                return;
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
        });

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Member added to team.']);
    }

    public function destroy(Request $request, Team $team, Member $member): RedirectResponse
    {
        abort_unless(WorkspaceAccess::canManageRosterOf($request->user(), $team), 403);

        $team->members()->detach($member->id);

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Member removed from team.']);
    }

    public function updateRole(UpdateTeamMemberRoleRequest $request, Team $team, Member $member): RedirectResponse
    {
        $team->members()->updateExistingPivot($member->id, ['role' => $request->validated('role')]);

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Team role updated.']);
    }
}
