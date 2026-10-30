<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\SearchTeamCandidatesRequest;
use App\Http\Requests\StoreTeamMemberRequest;
use App\Http\Requests\UpdateTeamMemberRoleRequest;
use App\Models\Member;
use App\Models\Team;
use App\Queries\TeamCandidatesQuery;
use App\Services\Workspace\RemoveTeamMemberService;
use App\Services\Workspace\UpdateTeamRoleService;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * A project team's roster, the surface its manager works through: search for
 * a person, add them, take them off. The manager (the team's one leader) is
 * chosen by the office admin, a super-admin or the project's program
 * manager, never by a manager.
 */
class TeamMemberController extends Controller
{
    use RedirectsWithServiceResult;

    public function candidates(SearchTeamCandidatesRequest $request, Team $team, TeamCandidatesQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get($team, (string) $request->validated('q'))]);
    }

    public function store(StoreTeamMemberRequest $request, Team $team): RedirectResponse
    {
        $team->members()->syncWithoutDetaching([$request->integer('member_id')]);

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Added to '.$team->name.'.']);
    }

    public function destroy(Request $request, Team $team, Member $member, RemoveTeamMemberService $service): RedirectResponse
    {
        abort_unless(WorkspaceAccess::canRemoveFromTeam($request->user(), $team, $member), 403);

        return $this->redirectWithResult($service->execute($team, $member));
    }

    public function updateRole(UpdateTeamMemberRoleRequest $request, Team $team, Member $member, UpdateTeamRoleService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($team, $member, (string) $request->validated('role')));
    }
}
