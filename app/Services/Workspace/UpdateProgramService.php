<?php

namespace App\Services\Workspace;

use App\Models\Program;
use App\Support\ServiceResult;
use Throwable;

class UpdateProgramService
{
    /**
     * @param  array<string, mixed>  $attributes  name, description, manager_id
     */
    public function execute(Program $program, array $attributes): ServiceResult
    {
        try {
            $program->update($attributes);

            return ServiceResult::success($program->refresh(), 'Program updated.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
