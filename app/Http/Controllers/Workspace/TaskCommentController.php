<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\StoreTaskCommentRequest;
use App\Http\Requests\UpdateTaskCommentRequest;
use App\Http\Resources\TaskCommentResource;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\Workspace\CreateTaskCommentService;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Services\Workspace\UpdateTaskCommentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class TaskCommentController extends Controller
{
    use RedirectsWithServiceResult;

    public function index(Task $task): AnonymousResourceCollection
    {
        $comments = $task->comments()->with('user')->latest()->paginate(30);

        TaskCommentResource::preload($comments->getCollection());

        return TaskCommentResource::collection($comments);
    }

    public function store(StoreTaskCommentRequest $request, Task $task, CreateTaskCommentService $service): RedirectResponse
    {
        $result = $service->execute($task, $request->user(), $request->validated('body'));

        return $this->redirectWithResult($result);
    }

    public function update(UpdateTaskCommentRequest $request, TaskComment $comment, UpdateTaskCommentService $service): RedirectResponse
    {
        $result = $service->execute($comment, $request->validated('body'));

        return $this->redirectWithResult($result);
    }

    public function destroy(TaskComment $comment, Request $request): RedirectResponse
    {
        abort_unless($comment->user_id === $request->user()->id, 403);

        $comment->delete();

        return back()->with('workspace_flash', [
            'success' => true,
            'message' => 'Comment deleted.',
            'undo' => [
                'label' => 'Undo',
                'url' => route('workspace.comments.restore', $comment),
            ],
        ]);
    }

    public function restore(TaskComment $comment, RestoreWorkspaceModel $service, Request $request): RedirectResponse
    {
        abort_unless($comment->user_id === $request->user()->id, 403);

        $service->execute($comment);

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Restored.']);
    }
}
