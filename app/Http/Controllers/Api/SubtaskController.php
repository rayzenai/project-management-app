<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\StoreSubtaskRequest;
use App\Http\Requests\UpdateSubtaskRequest;
use App\Http\Resources\SubtaskResource;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\Workspace\CreateSubtaskService;
use App\Services\Workspace\DeleteSubtaskService;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Services\Workspace\UpdateSubtaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\SubtaskController. Reuses the same FormRequests
 * (authorization), action services (ServiceResult), and JsonResources as the web
 * surface — the only difference is the response shape.
 */
class SubtaskController extends Controller
{
    use RespondsWithServiceResult;

    public function store(StoreSubtaskRequest $request, Task $task, CreateSubtaskService $service): JsonResponse
    {
        $result = $service->execute(
            task: $task,
            userId: $request->user()->id,
            attributes: $request->validated(),
        );

        return $this->respondWithResult(
            $result,
            $result->data instanceof Subtask ? new SubtaskResource($result->data) : null,
            $result->success ? 201 : null,
        );
    }

    public function update(UpdateSubtaskRequest $request, Subtask $subtask, UpdateSubtaskService $service): JsonResponse
    {
        abort_unless($subtask->user_id === $request->user()->id, 403);

        $result = $service->execute($subtask, $request->validated());

        return $this->respondWithResult(
            $result,
            $result->data instanceof Subtask ? new SubtaskResource($result->data) : null,
        );
    }

    public function destroy(Subtask $subtask, DeleteSubtaskService $service, Request $request): JsonResponse
    {
        abort_unless($subtask->user_id === $request->user()->id, 403);

        $result = $service->execute($subtask);

        return $this->respondWithResult($result);
    }

    public function restore(Subtask $subtask, RestoreWorkspaceModel $service, Request $request): JsonResponse
    {
        abort_unless($subtask->user_id === $request->user()->id, 403);

        $result = $service->execute($subtask);

        return $this->respondWithResult(
            $result,
            $result->data instanceof Subtask ? new SubtaskResource($result->data) : null,
        );
    }
}
