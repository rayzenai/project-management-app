<?php

namespace App\Http\Controllers\Api;

use App\Models\Project;
use App\Queries\ProjectProgressQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\ProjectProgressController.
 */
class ProjectProgressController extends Controller
{
    public function __invoke(Request $request, Project $project, ProjectProgressQuery $query): JsonResponse
    {
        abort_unless(WorkspaceAccess::canViewProjectProgress($request->user(), $project), 403);

        return response()->json(['data' => $query->data($project)]);
    }
}
