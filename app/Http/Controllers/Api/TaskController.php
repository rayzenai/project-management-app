<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Queries\TaskShowQuery;
use App\Services\Workspace\CreateTaskService;
use App\Services\Workspace\DeleteTaskService;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Services\Workspace\UpdateTaskService;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\TaskController. Reuses the same FormRequests
 * (authorization), action services (ServiceResult), JsonResources, and read
 * query as the web surface — the only difference is the response shape.
 */
class TaskController extends Controller
{
    use RespondsWithServiceResult;

    public function show(Request $request, Project $project, Task $task, TaskShowQuery $query): JsonResponse
    {
        abort_unless(WorkspaceAccess::canViewProject($request->user(), $project), 403);

        abort_unless($task->project_id === $project->id, 404);

        return response()->json($query->data($project, $task, $request->user()->id));
    }

    public function store(StoreTaskRequest $request, Project $project, CreateTaskService $service): JsonResponse
    {
        abort_unless(WorkspaceAccess::canViewProject($request->user(), $project), 403);

        $result = $service->execute($project, $request->validated());

        return $this->respondWithResult(
            $result,
            $result->data instanceof Task ? new TaskResource($result->data) : null,
            $result->success ? 201 : null,
        );
    }

    public function update(UpdateTaskRequest $request, Project $project, Task $task, UpdateTaskService $service): JsonResponse
    {
        abort_unless(WorkspaceAccess::canViewProject($request->user(), $project), 403);

        abort_unless($task->project_id === $project->id, 404);

        $result = $service->execute($task, $request->validated());

        return $this->respondWithResult(
            $result,
            $result->data instanceof Task ? new TaskResource($result->data) : null,
        );
    }

    public function destroy(Request $request, Project $project, Task $task, DeleteTaskService $service): JsonResponse
    {
        abort_unless(WorkspaceAccess::canViewProject($request->user(), $project), 403);

        abort_unless($task->project_id === $project->id, 404);

        $result = $service->execute($task);

        return $this->respondWithResult($result);
    }

    public function restore(Request $request, Project $project, Task $task, RestoreWorkspaceModel $service): JsonResponse
    {
        abort_unless(WorkspaceAccess::canViewProject($request->user(), $project), 403);

        abort_unless($task->project_id === $project->id, 404);

        $result = $service->execute($task);

        return $this->respondWithResult(
            $result,
            $result->data instanceof Task ? new TaskResource($result->data) : null,
        );
    }
}
