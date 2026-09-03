<?php

namespace App\Http\Controllers\Api;

use App\Models\Task;
use App\Queries\TaskPreviewQuery;
use App\Support\ApiResponser;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\TaskPreviewController. Both endpoints are
 * JSON-only and share the same TaskPreviewQuery — the API mirrors the web
 * response envelope exactly.
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
