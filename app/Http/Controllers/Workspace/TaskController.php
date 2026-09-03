<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Project;
use App\Models\Task;
use App\Queries\TaskShowQuery;
use App\Services\Workspace\CreateTaskService;
use App\Services\Workspace\DeleteTaskService;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Services\Workspace\UpdateTaskService;
use App\Support\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    use RedirectsWithServiceResult;

    public function show(Request $request, Project $project, Task $task, TaskShowQuery $query): Response
    {
        abort_unless(WorkspaceAccess::canViewProject($request->user(), $project), 403);

        abort_unless($task->project_id === $project->id, 404);

        return Inertia::render('Tasks/Show', $query->data($project, $task, $request->user()->id));
    }

    public function store(StoreTaskRequest $request, Project $project, CreateTaskService $service): RedirectResponse
    {
        abort_unless(WorkspaceAccess::canViewProject($request->user(), $project), 403);

        $result = $service->execute($project, $request->validated());

        if ($result->success && $result->data instanceof Task) {
            return redirect()
                ->route('workspace.tasks.show', ['project' => $project->slug, 'task' => $result->data->slug])
                ->with('workspace_flash', ['success' => true, 'message' => $result->message]);
        }

        return $this->redirectWithResult($result);
    }

    public function update(UpdateTaskRequest $request, Project $project, Task $task, UpdateTaskService $service): RedirectResponse
    {
        abort_unless(WorkspaceAccess::canViewProject($request->user(), $project), 403);

        abort_unless($task->project_id === $project->id, 404);

        $result = $service->execute($task, $request->validated());

        return $this->redirectWithResult($result);
    }

    public function destroy(Request $request, Project $project, Task $task, DeleteTaskService $service): RedirectResponse
    {
        abort_unless(WorkspaceAccess::canViewProject($request->user(), $project), 403);

        abort_unless($task->project_id === $project->id, 404);

        $result = $service->execute($task);

        if ($result->success) {
            return redirect()
                ->route('workspace.projects.show', ['project' => $project->slug])
                ->with('workspace_flash', [
                    'success' => true,
                    'message' => $result->message,
                    'undo' => [
                        'label' => 'Undo',
                        'url' => route('workspace.tasks.restore', [$project, $task]),
                    ],
                ]);
        }

        return $this->redirectWithResult($result);
    }

    public function restore(Request $request, Project $project, Task $task, RestoreWorkspaceModel $service): RedirectResponse
    {
        abort_unless(WorkspaceAccess::canViewProject($request->user(), $project), 403);

        abort_unless($task->project_id === $project->id, 404);

        $service->execute($task);

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Restored.']);
    }
}
