<?php

namespace App\Services\Workspace;

use App\Models\Program;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deleting a program keeps its projects: each is taken out one by one (so
 * the audit trail sees it), then the program goes.
 */
class DeleteProgramService
{
    public function execute(Program $program): ServiceResult
    {
        try {
            DB::transaction(function () use ($program): void {
                foreach ($program->projects()->get() as $project) {
                    $project->update(['program_id' => null]);
                }

                $program->delete();
            });

            return ServiceResult::success(null, 'Program deleted. Its projects stay.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
