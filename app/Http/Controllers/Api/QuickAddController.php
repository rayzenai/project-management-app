<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\QuickAddTaskRequest;
use App\Http\Resources\TaskResource;
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
            projectId: $request->integer('project_id'),
            explicitAssigneeIds: array_values(array_map('intval', (array) ($request->input('assignee_member_ids') ?: []))),
            priority: $request->input('priority'),
            deadline: $request->date('deadline_at')?->toDateString(),
            user: $request->user(),
        );

        return $this->respondWithResult(
            $result,
            $result->data instanceof Task ? new TaskResource($result->data) : null,
            $result->success ? 201 : null,
        );
    }
}
