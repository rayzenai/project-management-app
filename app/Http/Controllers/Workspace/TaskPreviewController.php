<?php

namespace App\Http\Controllers\Workspace;

use App\Models\Task;
use App\Queries\TaskPreviewQuery;
use App\Support\ApiResponser;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON-only endpoint backing the Task Peek slide-over. Returns the full
 * editable context for one task — fields, assignments (with ids so the Peek
 * can unassign), subtasks, notes, contacts, recent activity, and the assignee
 * candidate list — without a full-page Inertia visit.
 */
class TaskPreviewController extends Controller
{
    use ApiResponser;

    public function __invoke(Request $request, Task $task, TaskPreviewQuery $query): JsonResponse
    {
        abort_unless(WorkspaceAccess::canViewProject($request->user(), $task->loadMissing('project')->project), 403);

        return $this->dataResponse($query->data($task));
    }
}
