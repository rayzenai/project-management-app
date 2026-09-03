<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\QuickAddTaskRequest;
use App\Services\Workspace\QuickAddDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Creates a task from a single line of natural language. All parsing/resolution
 * lives in {@see QuickAddDispatcher}, shared with the API controller; this
 * controller only adapts the request to the dispatcher and the result to a redirect.
 */
class QuickAddController extends Controller
{
    use RedirectsWithServiceResult;

    public function __invoke(QuickAddTaskRequest $request, QuickAddDispatcher $dispatcher): RedirectResponse
    {
        $result = $dispatcher->dispatch(
            rawTitle: $request->string('title')->toString(),
            projectId: $request->integer('project_id'),
            explicitAssigneeIds: array_values(array_map('intval', (array) ($request->input('assignee_member_ids') ?: []))),
            priority: $request->input('priority'),
            deadline: $request->date('deadline_at')?->toDateString(),
            user: $request->user(),
        );

        return $this->redirectWithResult($result);
    }
}
