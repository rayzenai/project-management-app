<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\OpensOrganization;
use App\Queries\Structure\OrganizationChoicesQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Opening an organization's maintenance list: whoever administers it
 * (WorkspaceAccess::canViewMaintenance), on the organization
 * OpensOrganization opens.
 */
class MaintenanceRequest extends FormRequest
{
    use OpensOrganization;

    public function authorize(OrganizationChoicesQuery $organizations): bool
    {
        $organizationId = $this->openOrganization($organizations);

        return $organizationId === null
            ? $this->opensNothing()
            : WorkspaceAccess::canViewMaintenance($this->user(), $organizationId);
    }
}
