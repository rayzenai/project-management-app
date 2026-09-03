<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Requests\UpdateAssignmentRequest;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Services\Workspace\AssignMemberService;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Services\Workspace\UnassignMemberService;
use App\Services\Workspace\UpdateAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

class AssignmentController extends Controller
{
    use RedirectsWithServiceResult;

    public function store(StoreAssignmentRequest $request, Task $task, AssignMemberService $service): RedirectResponse
    {
        $result = $service->execute(
            task: $task,
            memberId: $request->integer('member_id'),
            attributes: $request->safe()->except('member_id'),
        );

        return $this->redirectWithResult($result);
    }

    public function update(UpdateAssignmentRequest $request, ProjectAssignment $assignment, UpdateAssignmentService $service): RedirectResponse
    {
        $result = $service->execute($assignment, $request->validated());

        return $this->redirectWithResult($result);
    }

    public function destroy(ProjectAssignment $assignment, UnassignMemberService $service): RedirectResponse
    {
        $result = $service->execute($assignment);

        return $this->redirectWithResult($result, undo: [
            'label' => 'Undo',
            'url' => route('workspace.assignments.restore', $assignment),
        ]);
    }

    public function restore(ProjectAssignment $assignment, RestoreWorkspaceModel $service): RedirectResponse
    {
        $service->execute($assignment);

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Restored.']);
    }
}
