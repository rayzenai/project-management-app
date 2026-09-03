<?php

namespace App\Services\Workspace;

use App\Models\Task;
use App\Support\ServiceResult;
use Throwable;

class DeleteTaskService
{
    public function execute(Task $task): ServiceResult
    {
        try {
            $task->delete();

            return ServiceResult::success(message: 'Task deleted.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
