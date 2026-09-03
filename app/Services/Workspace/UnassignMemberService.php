<?php

namespace App\Services\Workspace;

use App\Models\ProjectAssignment;
use App\Support\ServiceResult;
use Throwable;

class UnassignMemberService
{
    public function execute(ProjectAssignment $assignment): ServiceResult
    {
        try {
            $assignment->delete();

            return ServiceResult::success(message: 'Assignment removed.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
