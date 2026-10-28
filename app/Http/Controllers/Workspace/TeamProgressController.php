<?php

namespace App\Http\Controllers\Workspace;

use App\Models\Team;
use App\Support\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * A team's progress is its project's: old links land there.
 */
class TeamProgressController extends Controller
{
    public function __invoke(Request $request, Team $team): RedirectResponse
    {
        $project = $team->project();

        abort_unless($project !== null && WorkspaceAccess::canViewProjectProgress($request->user(), $project), 404);

        return redirect()->route('workspace.projects.progress', $project->slug);
    }
}
