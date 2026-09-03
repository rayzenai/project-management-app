<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Models\Project;
use App\Services\Workspace\ReorderTasksService;
use App\Support\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TaskReorderController extends Controller
{
    use RedirectsWithServiceResult;

    public function __invoke(Request $request, Project $project, ReorderTasksService $service): RedirectResponse
    {
        abort_unless(WorkspaceAccess::canViewProject($request->user(), $project), 403);

        $data = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer'],
            'status' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $service->execute($project, $data['task_ids'], $data['status'] ?? null);

        return $this->redirectWithResult($result);
    }
}
