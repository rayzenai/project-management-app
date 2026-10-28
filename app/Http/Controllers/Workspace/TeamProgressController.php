<?php

namespace App\Http\Controllers\Workspace;

use App\Models\Team;
use App\Queries\TeamProgressQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A team's live progress page (members' workload, Gantt chart, activity),
 * for its leaders and whoever administers its organization. The page polls
 * this same URL for fresh `progress` props.
 */
class TeamProgressController extends Controller
{
    public function __invoke(Request $request, Team $team, TeamProgressQuery $query): Response
    {
        abort_unless(WorkspaceAccess::canViewTeamProgress($request->user(), $team), 403);

        return Inertia::render('Team/Progress', ['progress' => $query->data($team)]);
    }
}
