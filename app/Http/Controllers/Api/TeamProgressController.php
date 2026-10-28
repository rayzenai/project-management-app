<?php

namespace App\Http\Controllers\Api;

use App\Models\Team;
use App\Queries\TeamProgressQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\TeamProgressController.
 */
class TeamProgressController extends Controller
{
    public function __invoke(Request $request, Team $team, TeamProgressQuery $query): JsonResponse
    {
        abort_unless(WorkspaceAccess::canViewTeamProgress($request->user(), $team), 403);

        return response()->json(['data' => $query->data($team)]);
    }
}
