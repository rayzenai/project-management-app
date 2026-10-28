<?php

namespace App\Services\Workspace;

use App\Models\Task;
use App\Models\TaskDependency;
use App\Support\ServiceResult;

class RemoveTaskDependencyService
{
    public function execute(Task $task, Task $dependsOn): ServiceResult
    {
        $dependency = TaskDependency::query()
            ->where('task_id', $task->id)
            ->where('depends_on_task_id', $dependsOn->id)
            ->first();

        if ($dependency === null) {
            return ServiceResult::failure('This task does not depend on that one.', 404);
        }

        // Deleted through the model so the observer logs it.
        $dependency->delete();

        return ServiceResult::success(null, 'Dependency removed.');
    }
}
