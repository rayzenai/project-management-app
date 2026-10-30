<?php

namespace App\Services\Workspace;

use App\Models\Program;
use App\Models\Project;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Creates a program with its "General" project, managed by the program
 * manager, so there is somewhere to add a task and assign someone at once.
 * Either both exist or neither does.
 */
class CreateProgramService
{
    public const GENERAL_TITLE = 'General';

    public function __construct(private CreateProjectService $projects) {}

    /**
     * @param  array<string, mixed>  $attributes  name, description, organization_id, manager_id
     */
    public function execute(array $attributes): ServiceResult
    {
        try {
            $program = DB::transaction(function () use ($attributes): Program {
                $program = Program::query()->create($attributes);

                $general = $this->projects->execute([
                    'title' => self::GENERAL_TITLE,
                    'organization_id' => $program->organization_id,
                    'program_id' => $program->id,
                    'manager_id' => $program->manager_id,
                ]);

                if (! $general->success || ! $general->data instanceof Project) {
                    throw new RuntimeException('The General project could not be created: '.$general->message);
                }

                return $program;
            });

            return ServiceResult::success($program, 'Program created.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
