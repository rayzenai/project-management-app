<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Requests\StoreTeamRequest;
use App\Http\Requests\UpdateTeamRequest;
use App\Models\Team;
use App\Queries\TeamIndexQuery;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Support\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request, TeamIndexQuery $query): Response
    {
        return Inertia::render('Team/Index', $query->data($request));
    }

    public function store(StoreTeamRequest $request): RedirectResponse
    {
        $team = Team::create(Arr::except($request->validated(), ['member_ids']));

        $team->members()->sync($request->validated('member_ids', []));

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Team created.']);
    }

    public function update(UpdateTeamRequest $request, Team $team): RedirectResponse
    {
        $team->update(Arr::except($request->validated(), ['member_ids']));

        if ($request->has('member_ids')) {
            $team->members()->sync($request->validated('member_ids', []));
        }

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Team updated.']);
    }

    public function destroy(Request $request, Team $team): RedirectResponse
    {
        abort_unless(WorkspaceAccess::canEditTeam($request->user(), $team), 403);

        $team->delete();

        return back()->with('workspace_flash', [
            'success' => true,
            'message' => 'Team deleted.',
            'undo' => [
                'label' => 'Undo',
                'url' => route('workspace.teams.restore', $team),
            ],
        ]);
    }

    public function restore(Request $request, Team $team, RestoreWorkspaceModel $service): RedirectResponse
    {
        abort_unless(WorkspaceAccess::canEditTeam($request->user(), $team), 403);

        $service->execute($team);

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Restored.']);
    }
}
