<?php

namespace App\Services\Workspace;

use App\Models\PersonalTodo;
use App\Support\ServiceResult;
use Throwable;

/**
 * Deletes a private to-do for good: it is its owner's alone, so there is no
 * trash to keep it in.
 */
class DeletePersonalTodoService
{
    public function execute(PersonalTodo $todo): ServiceResult
    {
        try {
            $todo->delete();

            return ServiceResult::success(message: 'To-do deleted.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
