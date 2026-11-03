<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\ListPersonalTodosRequest;
use App\Http\Requests\MovePersonalTodoRequest;
use App\Http\Requests\PersonalTodoRequest;
use App\Http\Requests\UpdatePersonalTodoRequest;
use App\Models\PersonalTodo;
use App\Models\Task;
use App\Queries\PersonalTodosQuery;
use App\Services\Workspace\DeletePersonalTodoService;
use App\Services\Workspace\MovePersonalTodoService;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Services\Workspace\UpdatePersonalTodoService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * A private to-do's own actions on My work and in the top bar's to-do
 * list: change it, delete it (and its Undo), or move it into a project as a
 * task. Each request lets only its owner through. They are added by quick
 * add's "Just for me". The list itself is JSON, for the panel's fetch.
 */
class PersonalTodoController extends Controller
{
    use RedirectsWithServiceResult;

    /** The caller's to-dos (PersonalTodosQuery) and the office's today, for the labels. */
    public function index(ListPersonalTodosRequest $request): JsonResponse
    {
        return response()->json([
            'today' => CarbonImmutable::today()->toDateString(),
            ...PersonalTodosQuery::for($request->owner()),
        ]);
    }

    public function update(UpdatePersonalTodoRequest $request, PersonalTodo $todo, UpdatePersonalTodoService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($todo, $request->validated()));
    }

    public function destroy(PersonalTodoRequest $request, PersonalTodo $todo, DeletePersonalTodoService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($todo));
    }

    /** A deleted to-do's Undo. */
    public function restore(PersonalTodoRequest $request, PersonalTodo $todo, RestoreWorkspaceModel $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($todo));
    }

    /** Lands on the new task, wherever it went. */
    public function move(MovePersonalTodoRequest $request, PersonalTodo $todo, MovePersonalTodoService $service): RedirectResponse
    {
        $result = $service->execute($todo, $request->integer('project_id'), $request->user());
        $task = $result->data;

        if ($result->success && $task instanceof Task) {
            return redirect()
                ->route('workspace.tasks.show', ['project' => $task->project->slug, 'task' => $task->slug])
                ->with('workspace_flash', ['success' => true, 'message' => $result->message]);
        }

        return $this->redirectWithResult($result);
    }
}
