<?php

namespace App\Services\Workspace;

use App\Models\PersonalTodo;
use App\Support\ServiceResult;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * Adds a private to-do for its owner (quick add's "Just for me", through
 * QuickAddDispatcher). Nothing is logged and nobody is told.
 */
class CreatePersonalTodoService
{
    public function execute(Authenticatable $owner, string $title, ?string $dueOn): ServiceResult
    {
        $title = trim($title);

        if ($title === '') {
            return ServiceResult::failure('Title is required.', 422);
        }

        try {
            $todo = PersonalTodo::create([
                'user_id' => $owner->getAuthIdentifier(),
                'title' => $title,
                'due_on' => $dueOn,
            ]);

            return ServiceResult::success($todo->fresh(), 'Added to your to-dos.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
