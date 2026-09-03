<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\StoreNoteRequest;
use App\Http\Resources\NoteResource;
use App\Models\ProjectNote;
use App\Models\Task;
use App\Services\Workspace\AddNoteService;
use App\Services\Workspace\DeleteNoteService;
use App\Services\Workspace\RestoreWorkspaceModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\NoteController. Reuses the same FormRequests
 * (authorization), action services (ServiceResult), and JsonResources as the web
 * surface — the only difference is the response shape.
 */
class NoteController extends Controller
{
    use RespondsWithServiceResult;

    public function store(StoreNoteRequest $request, Task $task, AddNoteService $service): JsonResponse
    {
        $result = $service->execute(
            task: $task,
            userId: $request->user()->id,
            attributes: $request->validated(),
        );

        return $this->respondWithResult(
            $result,
            $result->data instanceof ProjectNote ? new NoteResource($result->data) : null,
            $result->success ? 201 : null,
        );
    }

    public function destroy(ProjectNote $note, DeleteNoteService $service): JsonResponse
    {
        $result = $service->execute($note);

        return $this->respondWithResult($result);
    }

    public function restore(ProjectNote $note, RestoreWorkspaceModel $service): JsonResponse
    {
        $result = $service->execute($note);

        return $this->respondWithResult(
            $result,
            $result->data instanceof ProjectNote ? new NoteResource($result->data) : null,
        );
    }
}
