<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\StoreWorkspaceNoteRequest;
use App\Http\Requests\UpdateWorkspaceNotePlacementRequest;
use App\Http\Requests\UpdateWorkspaceNoteRequest;
use App\Models\WorkspaceNote;
use App\Services\Workspace\CreateWorkspaceNoteService;
use App\Services\Workspace\DeleteWorkspaceNoteService;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Services\Workspace\UpdateWorkspaceNotePlacementService;
use App\Services\Workspace\UpdateWorkspaceNoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WorkspaceNoteController extends Controller
{
    use RedirectsWithServiceResult;

    public function store(StoreWorkspaceNoteRequest $request, CreateWorkspaceNoteService $service): RedirectResponse
    {
        $result = $service->execute(
            userId: $request->user()->id,
            attributes: $request->validated(),
        );

        return $this->redirectWithResult($result);
    }

    public function update(UpdateWorkspaceNoteRequest $request, WorkspaceNote $workspaceNote, UpdateWorkspaceNoteService $service): RedirectResponse
    {
        $this->authorizeOwnership($request, $workspaceNote);

        $result = $service->execute($workspaceNote, $request->validated());

        return $this->redirectWithResult($result);
    }

    public function placement(UpdateWorkspaceNotePlacementRequest $request, WorkspaceNote $workspaceNote, UpdateWorkspaceNotePlacementService $service): RedirectResponse
    {
        $this->authorizeOwnership($request, $workspaceNote);

        $result = $service->execute($workspaceNote, $request->validated());

        return $this->redirectWithResult($result);
    }

    public function destroy(WorkspaceNote $workspaceNote, DeleteWorkspaceNoteService $service): RedirectResponse
    {
        $this->authorizeOwnership(request(), $workspaceNote);

        $result = $service->execute($workspaceNote);

        return $this->redirectWithResult($result, undo: [
            'label' => 'Undo',
            'url' => route('workspace.my-notes.restore', $workspaceNote),
        ]);
    }

    public function restore(WorkspaceNote $workspaceNote, RestoreWorkspaceModel $service): RedirectResponse
    {
        $this->authorizeOwnership(request(), $workspaceNote);

        $service->execute($workspaceNote);

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Restored.']);
    }

    private function authorizeOwnership(Request $request, WorkspaceNote $note): void
    {
        abort_unless($note->user_id === $request->user()?->id, 403);
    }
}
