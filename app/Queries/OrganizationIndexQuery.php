<?php

namespace App\Queries;

use App\Http\Resources\OrganizationResource;
use App\Models\Organization;

/**
 * The super-admin's list of organizations with their counts and office
 * admins. Shared by the web page and the JSON API.
 */
class OrganizationIndexQuery
{
    /**
     * @return array{organizations: array<int, array<string, mixed>>}
     */
    public function data(): array
    {
        $organizations = Organization::query()
            ->withCount(['members', 'teams', 'projects'])
            ->with('gitConnection')
            ->with(['members' => fn ($q) => $q->where('is_office_admin', true)->orderBy('name')])
            ->orderBy('name')
            ->get();

        return [
            'organizations' => OrganizationResource::collection($organizations)->resolve(),
        ];
    }
}
