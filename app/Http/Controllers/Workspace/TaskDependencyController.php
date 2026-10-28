<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\StoreTaskDependencyRequest;
use App\Models\Task;
use App\Services\Workspace\AddTaskDependencyService;
use App\Services\Workspace\RemoveTaskDependencyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Finish-to-start dependencies between a project's tasks. Visibility of the
 * task is enforced by EnsureProjectVisible on the route; the request keeps
 * both tasks in one project.
 */
class TaskDependencyController extends Controller
{
    use RedirectsWithServiceResult;

    public function store(StoreTaskDependencyRequest $request, Task $task, AddTaskDependencyService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($task, $request->integer('depends_on_task_id')));
    }

    public function destroy(Task $task, Task $dependsOn, RemoveTaskDependencyService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($task, $dependsOn));
    }
}
