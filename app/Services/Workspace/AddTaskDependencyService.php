<?php

namespace App\Services\Workspace;

use App\Models\Task;
use App\Models\TaskDependency;
use App\Support\ServiceResult;
use Throwable;

class AddTaskDependencyService
{
    public function execute(Task $task, int $dependsOnId): ServiceResult
    {
        try {
            $dependency = TaskDependency::create([
                'task_id' => $task->id,
                'depends_on_task_id' => $dependsOnId,
            ]);

            return ServiceResult::success($dependency, 'Dependency added.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
