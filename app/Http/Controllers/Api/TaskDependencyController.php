<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\StoreTaskDependencyRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\Workspace\AddTaskDependencyService;
use App\Services\Workspace\RemoveTaskDependencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\TaskDependencyController. Responds with the task
 * and its dependencies as they now stand.
 */
class TaskDependencyController extends Controller
{
    use RespondsWithServiceResult;

    public function store(StoreTaskDependencyRequest $request, Task $task, AddTaskDependencyService $service): JsonResponse
    {
        $result = $service->execute($task, $request->integer('depends_on_task_id'));

        return $this->respondWithResult($result, $this->taskPayload($task), 201);
    }

    public function destroy(Task $task, Task $dependsOn, RemoveTaskDependencyService $service): JsonResponse
    {
        return $this->respondWithResult($service->execute($task, $dependsOn), $this->taskPayload($task));
    }

    private function taskPayload(Task $task): TaskResource
    {
        return new TaskResource($task->fresh(['project', 'dependencies', 'dependents']));
    }
}
