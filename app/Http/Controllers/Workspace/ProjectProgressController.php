<?php

namespace App\Http\Controllers\Workspace;

use App\Models\Project;
use App\Queries\ProjectProgressQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A project's live progress page (people's workload, Gantt chart, activity),
 * for the people who run it. The page polls this same URL for fresh
 * `progress` props.
 */
class ProjectProgressController extends Controller
{
    public function __invoke(Request $request, Project $project, ProjectProgressQuery $query): Response
    {
        abort_unless(WorkspaceAccess::canViewProjectProgress($request->user(), $project), 403);

        return Inertia::render('Projects/Progress', ['progress' => $query->data($project)]);
    }
}
