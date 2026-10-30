<?php

namespace App\Services\Workspace;

use App\Models\Organization;
use App\Models\Project;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Starts an organization's first project, "General", managed by its
 * organizational admin, so an organization with no project can add a task
 * and assign someone at once (as every program starts with one, see
 * CreateProgramService). Only while it has no active project
 * ({@see WorkspaceAccess::canStartGeneralProject()}).
 */
class StartGeneralProjectService
{
    public function __construct(private CreateProjectService $projects) {}

    public function execute(Authenticatable $user): ServiceResult
    {
        $organizationId = WorkspaceAccess::officeAdminOrganizationId($user);
        $manager = WorkspaceAccess::memberFor($user);

        if ($organizationId === null || $manager === null) {
            return ServiceResult::failure('Pick a project.', 422);
        }

        try {
            return DB::transaction(function () use ($user, $organizationId, $manager): ServiceResult {
                // Serializes starts in the organization, so a double submit makes one General.
                Organization::query()->whereKey($organizationId)->lockForUpdate()->first();

                if (! WorkspaceAccess::canStartGeneralProject($user)) {
                    return ServiceResult::failure('Pick a project.', 422);
                }

                $general = $this->projects->execute([
                    'title' => CreateProgramService::GENERAL_TITLE,
                    'organization_id' => $organizationId,
                    'manager_id' => $manager->id,
                ]);

                $project = $general->data instanceof Project ? $general->data->fresh() : null;

                if (! $general->success || $project === null) {
                    throw new RuntimeException('The General project could not be created: '.$general->message);
                }

                if ($project->manager() === null) {
                    throw new RuntimeException('The General project has no manager.');
                }

                return ServiceResult::success($project, 'Project created.');
            });
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
