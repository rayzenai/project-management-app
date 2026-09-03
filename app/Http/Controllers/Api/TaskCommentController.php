<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\StoreTaskCommentRequest;
use App\Http\Requests\UpdateTaskCommentRequest;
use App\Http\Resources\TaskCommentResource;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\Workspace\CreateTaskCommentService;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Services\Workspace\UpdateTaskCommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\TaskCommentController. Reuses the same
 * FormRequests, action services, and JsonResource as the web surface — the
 * index batches author/mention member lookups via TaskCommentResource::preload.
 */
class TaskCommentController extends Controller
{
    use RespondsWithServiceResult;

    public function index(Task $task): AnonymousResourceCollection
    {
        $comments = $task->comments()->with('user')->latest()->paginate(30);

        TaskCommentResource::preload($comments->getCollection());

        return TaskCommentResource::collection($comments)->additional(['message' => 'Comments retrieved.']);
    }

    public function store(StoreTaskCommentRequest $request, Task $task, CreateTaskCommentService $service): JsonResponse
    {
        $result = $service->execute($task, $request->user(), $request->validated('body'));

        return $this->respondWithResult(
            $result,
            $result->data instanceof TaskComment ? new TaskCommentResource($result->data) : null,
            $result->success ? 201 : null,
        );
    }

    public function update(UpdateTaskCommentRequest $request, TaskComment $comment, UpdateTaskCommentService $service): JsonResponse
    {
        $result = $service->execute($comment, $request->validated('body'));

        return $this->respondWithResult(
            $result,
            $result->data instanceof TaskComment ? new TaskCommentResource($result->data) : null,
        );
    }

    public function destroy(TaskComment $comment, Request $request): JsonResponse
    {
        abort_unless($comment->user_id === $request->user()->id, 403);

        $comment->delete();

        return response()->json(['message' => 'Comment deleted.']);
    }

    public function restore(TaskComment $comment, RestoreWorkspaceModel $service, Request $request): JsonResponse
    {
        abort_unless($comment->user_id === $request->user()->id, 403);

        $result = $service->execute($comment);

        return $this->respondWithResult(
            $result,
            $result->data instanceof TaskComment ? new TaskCommentResource($result->data) : null,
        );
    }
}
