<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\StoreWorkspaceNoteRequest;
use App\Http\Requests\UpdateWorkspaceNotePlacementRequest;
use App\Http\Requests\UpdateWorkspaceNoteRequest;
use App\Http\Resources\NoteResource;
use App\Http\Resources\WorkspaceNoteResource;
use App\Models\Member;
use App\Models\ProjectNote;
use App\Models\WorkspaceNote;
use App\Services\Workspace\CreateWorkspaceNoteService;
use App\Services\Workspace\DeleteWorkspaceNoteService;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Services\Workspace\UpdateWorkspaceNotePlacementService;
use App\Services\Workspace\UpdateWorkspaceNoteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\WorkspaceNoteController. Reuses the same
 * FormRequests (authorization), action services (ServiceResult), JsonResources,
 * and the per-note ownership guard as the web surface — the only difference is
 * the response shape.
 */
class WorkspaceNoteController extends Controller
{
    use RespondsWithServiceResult;

    /**
     * The signed-in user's notes board, mirroring the two sources the web
     * surface shares via Inertia (ShareWorkspaceData) so the API/mobile client
     * can hydrate the same "My notes" view:
     *   • workspace_notes — the user's own free-form sticky notes, newest first
     *   • task_notes      — task-anchored notes the user authored OR that live
     *                       on a task assigned to them (latest 50)
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $memberId = Member::query()->where('user_id', $user->id)->value('id');

        $workspaceNotes = WorkspaceNote::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get();

        $taskNotes = ProjectNote::query()
            ->where(function (Builder $query) use ($user, $memberId): void {
                $query->where('user_id', $user->id);

                if ($memberId !== null) {
                    $query->orWhereHas(
                        'task.assignments',
                        fn (Builder $assignment): Builder => $assignment->where('member_id', $memberId),
                    );
                }
            })
            ->with(['user', 'task.project'])
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'data' => [
                'workspace_notes' => WorkspaceNoteResource::collection($workspaceNotes)->resolve(),
                'task_notes' => NoteResource::collection($taskNotes)->resolve(),
            ],
        ]);
    }

    public function store(StoreWorkspaceNoteRequest $request, CreateWorkspaceNoteService $service): JsonResponse
    {
        $result = $service->execute(
            userId: $request->user()->id,
            attributes: $request->validated(),
        );

        return $this->respondWithResult(
            $result,
            $result->data instanceof WorkspaceNote ? new WorkspaceNoteResource($result->data) : null,
            $result->success ? 201 : null,
        );
    }

    public function update(UpdateWorkspaceNoteRequest $request, WorkspaceNote $workspaceNote, UpdateWorkspaceNoteService $service): JsonResponse
    {
        $this->authorizeOwnership($request, $workspaceNote);

        $result = $service->execute($workspaceNote, $request->validated());

        return $this->respondWithResult(
            $result,
            $result->data instanceof WorkspaceNote ? new WorkspaceNoteResource($result->data) : null,
        );
    }

    public function placement(UpdateWorkspaceNotePlacementRequest $request, WorkspaceNote $workspaceNote, UpdateWorkspaceNotePlacementService $service): JsonResponse
    {
        $this->authorizeOwnership($request, $workspaceNote);

        $result = $service->execute($workspaceNote, $request->validated());

        return $this->respondWithResult(
            $result,
            $result->data instanceof WorkspaceNote ? new WorkspaceNoteResource($result->data) : null,
        );
    }

    public function destroy(WorkspaceNote $workspaceNote, DeleteWorkspaceNoteService $service, Request $request): JsonResponse
    {
        $this->authorizeOwnership($request, $workspaceNote);

        $result = $service->execute($workspaceNote);

        return $this->respondWithResult($result);
    }

    public function restore(WorkspaceNote $workspaceNote, RestoreWorkspaceModel $service, Request $request): JsonResponse
    {
        $this->authorizeOwnership($request, $workspaceNote);

        $result = $service->execute($workspaceNote);

        return $this->respondWithResult(
            $result,
            $result->data instanceof WorkspaceNote ? new WorkspaceNoteResource($result->data) : null,
        );
    }

    private function authorizeOwnership(Request $request, WorkspaceNote $note): void
    {
        abort_unless($note->user_id === $request->user()?->id, 403);
    }
}
