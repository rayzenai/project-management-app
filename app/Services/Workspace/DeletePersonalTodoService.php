<?php

namespace App\Services\Workspace;

use App\Models\PersonalTodo;
use App\Support\ServiceResult;
use Throwable;

/**
 * Deletes a private to-do into its owner's trash, where its Undo can bring
 * it back (POST .../restore) until workspace:prune-trashed removes it after
 * PersonalTodo::TRASH_DAYS.
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
