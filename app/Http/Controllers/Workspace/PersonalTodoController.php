<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\MovePersonalTodoRequest;
use App\Http\Requests\PersonalTodoRequest;
use App\Http\Requests\UpdatePersonalTodoRequest;
use App\Models\PersonalTodo;
use App\Models\Task;
use App\Services\Workspace\DeletePersonalTodoService;
use App\Services\Workspace\MovePersonalTodoService;
use App\Services\Workspace\UpdatePersonalTodoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * A private to-do's own actions on My work: change it, delete it, or move
 * it into a project as a task. Each request lets only its owner through.
 * They are added by quick add's "Just for me".
 */
class PersonalTodoController extends Controller
{
    use RedirectsWithServiceResult;

    public function update(UpdatePersonalTodoRequest $request, PersonalTodo $todo, UpdatePersonalTodoService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($todo, $request->validated()));
    }

    public function destroy(PersonalTodoRequest $request, PersonalTodo $todo, DeletePersonalTodoService $service): RedirectResponse
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
