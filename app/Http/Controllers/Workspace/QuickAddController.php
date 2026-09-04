<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\QuickAddTaskRequest;
use App\Models\Task;
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
            status: $request->input('status'),
            description: $request->input('description'),
        );

        // A task created from anywhere else in the app is invisible until you
        // find it, so the dialog asks to be dropped on its project board. The
        // server decides the destination because a #token can move the task to
        // a different project than the one the picker showed.
        $task = $result->data;

        if ($result->success && $request->boolean('redirect_to_project') && $task instanceof Task) {
            $project = $task->loadMissing('project')->project;

            if ($project !== null) {
                return redirect()
                    ->route('workspace.projects.show', ['project' => $project, 'view' => 'board'])
                    ->with('workspace_flash', ['success' => true, 'message' => $result->message]);
            }
        }

        return $this->redirectWithResult($result);
    }
}
