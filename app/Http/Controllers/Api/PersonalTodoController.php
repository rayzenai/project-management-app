<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\MovePersonalTodoRequest;
use App\Http\Requests\PersonalTodoRequest;
use App\Http\Requests\UpdatePersonalTodoRequest;
use App\Http\Resources\PersonalTodoResource;
use App\Http\Resources\TaskResource;
use App\Models\PersonalTodo;
use App\Models\Task;
use App\Services\Workspace\DeletePersonalTodoService;
use App\Services\Workspace\MovePersonalTodoService;
use App\Services\Workspace\UpdatePersonalTodoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\PersonalTodoController: the same requests
 * (owner only, 404 to anyone else) and services. The move answers 201 with
 * the new task.
 */
class PersonalTodoController extends Controller
{
    use RespondsWithServiceResult;

    public function update(UpdatePersonalTodoRequest $request, PersonalTodo $todo, UpdatePersonalTodoService $service): JsonResponse
    {
        $result = $service->execute($todo, $request->validated());

        return $this->respondWithResult(
            $result,
            $result->data instanceof PersonalTodo ? new PersonalTodoResource($result->data) : null,
        );
    }

    public function destroy(PersonalTodoRequest $request, PersonalTodo $todo, DeletePersonalTodoService $service): JsonResponse
    {
        return $this->respondWithResult($service->execute($todo));
    }

    public function move(MovePersonalTodoRequest $request, PersonalTodo $todo, MovePersonalTodoService $service): JsonResponse
    {
        $result = $service->execute($todo, $request->integer('project_id'), $request->user());

        return $this->respondWithResult(
            $result,
            $result->data instanceof Task ? new TaskResource($result->data) : null,
            $result->success ? 201 : null,
        );
    }
}
