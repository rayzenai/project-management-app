<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\StoreNoteRequest;
use App\Models\ProjectNote;
use App\Models\Task;
use App\Services\Workspace\AddNoteService;
use App\Services\Workspace\DeleteNoteService;
use App\Services\Workspace\RestoreWorkspaceModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

class NoteController extends Controller
{
    use RedirectsWithServiceResult;

    public function store(StoreNoteRequest $request, Task $task, AddNoteService $service): RedirectResponse
    {
        $result = $service->execute(
            task: $task,
            userId: $request->user()->id,
            attributes: $request->validated(),
        );

        return $this->redirectWithResult($result);
    }

    public function destroy(ProjectNote $note, DeleteNoteService $service): RedirectResponse
    {
        $result = $service->execute($note);

        return $this->redirectWithResult($result, undo: [
            'label' => 'Undo',
            'url' => route('workspace.notes.restore', $note),
        ]);
    }

    public function restore(ProjectNote $note, RestoreWorkspaceModel $service): RedirectResponse
    {
        $service->execute($note);

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Restored.']);
    }
}
