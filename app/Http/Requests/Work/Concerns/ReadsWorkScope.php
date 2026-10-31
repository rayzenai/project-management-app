<?php

namespace App\Http\Requests\Work\Concerns;

use App\Models\Member;
use App\Models\Organization;
use App\Queries\Work\WorkFiltersQuery;
use App\Support\WorkspaceAccess;

/**
 * Whose work a list report shows: an organization (the API's
 * `{organization}`, else `?organization=<id>`, else the viewer's own; a
 * super-admin with none opens the first by name) and optionally `under`, a
 * person whose team (them and everyone below them) narrows the list.
 *
 * Allowed when WorkspaceAccess::canViewTeamWork for the organization, and
 * `under`, when given, is in that organization and passes
 * WorkspaceAccess::canViewPersonOf. An unknown `under` is a 403 like one
 * outside the viewer's view, so the filter never confirms who exists.
 */
trait ReadsWorkScope
{
    private ?int $workOrganizationId = null;

    private ?Member $workUnder = null;

    protected function authorizeWorkScope(WorkFiltersQuery $filters): bool
    {
        $user = $this->user();
        $organizationId = $this->requestedOrganizationId($filters);

        if ($user === null || $organizationId === null || ! WorkspaceAccess::canViewTeamWork($user, $organizationId)) {
            return false;
        }

        $under = $this->query('under');

        if ($under !== null && $under !== '') {
            $id = is_string($under) ? filter_var($under, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            $member = $id === false ? null : Member::query()->find($id);

            if ($member === null || (int) $member->organization_id !== $organizationId || ! WorkspaceAccess::canViewPersonOf($user, $member)) {
                return false;
            }

            $this->workUnder = $member;
        }

        $this->workOrganizationId = $organizationId;

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function scopeRules(): array
    {
        return [
            'organization' => ['nullable', 'integer', 'exists:organizations,id'],
        ];
    }

    public function organizationId(): int
    {
        return (int) $this->workOrganizationId;
    }

    public function under(): ?Member
    {
        return $this->workUnder;
    }

    private function requestedOrganizationId(WorkFiltersQuery $filters): ?int
    {
        $routed = $this->route('organization');

        if ($routed instanceof Organization) {
            return (int) $routed->getKey();
        }

        $given = $this->query('organization');

        if ($given !== null && $given !== '') {
            $id = is_string($given) ? filter_var($given, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

            return $id === false ? null : $id;
        }

        $user = $this->user();

        return $user === null ? null : $filters->defaultOrganizationId($user);
    }
}
