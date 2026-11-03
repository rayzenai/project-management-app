<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\QuickAddTaskRequest;
use App\Http\Resources\PersonalTodoResource;
use App\Http\Resources\TaskResource;
use App\Models\PersonalTodo;
use App\Models\Task;
use App\Services\Workspace\QuickAddDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\QuickAddController. All parsing/resolution lives in
 * {@see QuickAddDispatcher}, shared with the web controller; this controller only
 * adapts the request to the dispatcher and the result to a JSON response.
 */
class QuickAddController extends Controller
{
    use RespondsWithServiceResult;

    public function __invoke(QuickAddTaskRequest $request, QuickAddDispatcher $dispatcher): JsonResponse
    {
        $result = $dispatcher->dispatch(
            rawTitle: $request->string('title')->toString(),
            projectId: $request->filled('project_id') ? $request->integer('project_id') : null,
            explicitAssigneeIds: array_values(array_map('intval', (array) ($request->input('assignee_member_ids') ?: []))),
            priority: $request->input('priority'),
            deadline: $request->date('deadline_at')?->toDateString(),
            user: $request->user(),
            status: $request->input('status'),
            description: $request->input('description'),
            dependsOnTaskIds: array_values(array_map('intval', (array) ($request->input('depends_on_task_ids') ?: []))),
            startGeneral: $request->startsGeneral(),
            personal: $request->isPersonal(),
        );

        // A task, or with `personal` the caller's private to-do.
        return $this->respondWithResult(
            $result,
            match (true) {
                $result->data instanceof Task => new TaskResource($result->data),
                $result->data instanceof PersonalTodo => new PersonalTodoResource($result->data),
                default => null,
            },
            $result->success ? 201 : null,
        );
    }
}
