<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Queries\ProjectIndexQuery;
use App\Queries\ProjectShowQuery;
use App\Services\Workspace\CreateProjectService;
use App\Services\Workspace\UpdateProjectService;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\ProjectController. Reuses the same FormRequests
 * (authorization), action services (ServiceResult), JsonResources, and read
 * queries as the web surface — the only difference is the response shape.
 */
class ProjectController extends Controller
{
    use RespondsWithServiceResult;

    public function index(Request $request, ProjectIndexQuery $query): JsonResponse
    {
        return response()->json($query->data($request));
    }

    public function show(Request $request, Project $project, ProjectShowQuery $query): JsonResponse
    {
        abort_unless(WorkspaceAccess::canViewProject($request->user(), $project), 403);

        return response()->json($query->data($project));
    }

    public function store(StoreProjectRequest $request, CreateProjectService $service): JsonResponse
    {
        $result = $service->execute($request->validated());

        return $this->respondWithResult(
            $result,
            $result->data instanceof Project ? new ProjectResource($result->data) : null,
            $result->success ? 201 : null,
        );
    }

    public function update(UpdateProjectRequest $request, Project $project, UpdateProjectService $service): JsonResponse
    {
        $result = $service->execute($project, $request->validated());

        return $this->respondWithResult(
            $result,
            $result->data instanceof Project ? new ProjectResource($result->data) : null,
        );
    }

    public function archive(Request $request, Project $project): JsonResponse
    {
        abort_unless(WorkspaceAccess::canArchiveProject($request->user(), $project), 403);

        $project->archive();

        return response()->json([
            'message' => 'Project archived.',
            'data' => new ProjectResource($project->fresh()),
        ]);
    }

    public function restore(Request $request, Project $project): JsonResponse
    {
        abort_unless(WorkspaceAccess::canArchiveProject($request->user(), $project), 403);

        $project->restore();

        return response()->json([
            'message' => 'Project restored.',
            'data' => new ProjectResource($project->fresh()),
        ]);
    }
}
