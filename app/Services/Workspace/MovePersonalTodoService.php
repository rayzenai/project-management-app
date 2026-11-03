<?php

namespace App\Services\Workspace;

use App\Models\PersonalTodo;
use App\Models\Task;
use App\Support\ServiceResult;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "Move to a project": a private to-do becomes a task in a project the
 * owner may add to, by quick add's own path for a project
 * (QuickAddDispatcher::fileTitle: the same refusals, its date as the
 * task's, the owner on it when they are assignable there), and the to-do
 * goes, in one transaction. A refused project leaves the to-do as it was.
 */
class MovePersonalTodoService
{
    public function __construct(private QuickAddDispatcher $quickAdd) {}

    public function execute(PersonalTodo $todo, int $projectId, Authenticatable $owner): ServiceResult
    {
        try {
            return DB::transaction(function () use ($todo, $projectId, $owner): ServiceResult {
                // Two moves at once file one task: the second finds it gone.
                $todo = PersonalTodo::query()->whereKey($todo->id)->lockForUpdate()->first();

                if ($todo === null) {
                    return ServiceResult::failure('That to-do is no longer there.', 404);
                }

                $filed = $this->quickAdd->fileTitle($projectId, $todo->title, $todo->due_on?->toDateString(), $owner);

                if (! $filed->success || ! $filed->data instanceof Task) {
                    return $filed;
                }

                // For good: no Undo may bring it back beside its task.
                $todo->forceDelete();

                return ServiceResult::success($filed->data, "Moved to {$filed->data->project->label()}.");
            });
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
