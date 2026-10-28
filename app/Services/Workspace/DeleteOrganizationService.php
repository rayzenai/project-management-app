<?php

namespace App\Services\Workspace;

use App\Models\Organization;
use App\Support\ServiceResult;

/**
 * Deletes an organization only once nothing belongs to it, so removing one
 * can never take a ministry's people, teams or projects with it.
 */
class DeleteOrganizationService
{
    public function execute(Organization $organization): ServiceResult
    {
        $inUse = $organization->members()->withTrashed()->exists()
            || $organization->teams()->withTrashed()->exists()
            || $organization->projects()->exists();

        if ($inUse) {
            return ServiceResult::failure('Move or delete its people, teams and projects first.', 422);
        }

        $organization->delete();

        return ServiceResult::success(null, 'Organization deleted.');
    }
}
