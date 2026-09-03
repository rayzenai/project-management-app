<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Requests\UpdateAssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Services\Workspace\AssignMemberService;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Services\Workspace\UnassignMemberService;
use App\Services\Workspace\UpdateAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\AssignmentController. Reuses the same FormRequests
 * (authorization), action services (ServiceResult), and JsonResources as the web
 * surface — the only difference is the response shape.
 */
class AssignmentController extends Controller
{
    use RespondsWithServiceResult;

    public function store(StoreAssignmentRequest $request, Task $task, AssignMemberService $service): JsonResponse
    {
        $result = $service->execute(
            task: $task,
            memberId: $request->integer('member_id'),
            attributes: $request->safe()->except('member_id'),
        );

        return $this->respondWithResult(
            $result,
            $result->data instanceof ProjectAssignment ? new AssignmentResource($result->data) : null,
            $result->success ? 201 : null,
        );
    }

    public function update(UpdateAssignmentRequest $request, ProjectAssignment $assignment, UpdateAssignmentService $service): JsonResponse
    {
        $result = $service->execute($assignment, $request->validated());

        return $this->respondWithResult(
            $result,
            $result->data instanceof ProjectAssignment ? new AssignmentResource($result->data) : null,
        );
    }

    public function destroy(ProjectAssignment $assignment, UnassignMemberService $service): JsonResponse
    {
        $result = $service->execute($assignment);

        return $this->respondWithResult($result);
    }

    public function restore(ProjectAssignment $assignment, RestoreWorkspaceModel $service): JsonResponse
    {
        $result = $service->execute($assignment);

        return $this->respondWithResult(
            $result,
            $result->data instanceof ProjectAssignment ? new AssignmentResource($result->data) : null,
        );
    }
}
