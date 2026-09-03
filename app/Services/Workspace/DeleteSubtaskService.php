<?php

namespace App\Services\Workspace;

use App\Models\Subtask;
use App\Support\ServiceResult;
use Throwable;

class DeleteSubtaskService
{
    public function execute(Subtask $subtask): ServiceResult
    {
        try {
            $subtask->delete();

            return ServiceResult::success(message: 'Todo removed.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
