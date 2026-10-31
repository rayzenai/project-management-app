<?php

namespace App\Http\Requests\Structure;

use App\Http\Requests\Concerns\OpensOrganization;
use App\Queries\Structure\OrganizationChoicesQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Opening an organization's organogram: super-admins and its active members
 * (WorkspaceAccess::canViewOrganogram), on the organization OpensOrganization
 * opens.
 */
class OrganogramRequest extends FormRequest
{
    use OpensOrganization;

    public function authorize(OrganizationChoicesQuery $organizations): bool
    {
        $organizationId = $this->openOrganization($organizations);

        return $organizationId === null
            ? $this->opensNothing()
            : WorkspaceAccess::canViewOrganogram($this->user(), $organizationId);
    }
}
