<?php

namespace App\Services\Workspace;

use App\Models\Program;
use App\Support\ServiceResult;
use Throwable;

class CreateProgramService
{
    /**
     * @param  array<string, mixed>  $attributes  name, description, organization_id, manager_id
     */
    public function execute(array $attributes): ServiceResult
    {
        try {
            $program = Program::query()->create($attributes);

            return ServiceResult::success($program, 'Program created.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
