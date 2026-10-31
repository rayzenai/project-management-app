<?php

namespace App\Http\Requests\Structure;

use App\Models\Organization;
use App\Queries\Structure\OrganizationChoicesQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening an organization's organogram: super-admins and its active members
 * (WorkspaceAccess::canViewOrganogram). The API names the organization in
 * the path; the web page shows the viewer's own, and a super-admin picks
 * one with `?organization=` (the first by name until they do).
 */
class OrganogramRequest extends FormRequest
{
    private ?int $organizationId = null;

    public function authorize(OrganizationChoicesQuery $organizations): bool
    {
        $user = $this->user();
        $routed = $this->route('organization');
        $picked = $this->query('organization');

        $this->organizationId = match (true) {
            $routed instanceof Organization => (int) $routed->getKey(),
            WorkspaceAccess::isSuperAdmin($user) && is_numeric($picked) => (int) $picked,
            default => $organizations->defaultId($user),
        };

        // Null only for a super-admin when there is no organization at all.
        if ($this->organizationId === null) {
            return ! $routed instanceof Organization && WorkspaceAccess::isSuperAdmin($user);
        }

        return WorkspaceAccess::canViewOrganogram($user, $this->organizationId);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization' => ['nullable', 'integer', Rule::exists('organizations', 'id')],
        ];
    }

    /**
     * The organization to draw: the path's (API), a super-admin's pick, or
     * OrganizationChoicesQuery's default.
     */
    public function organizationId(): ?int
    {
        return $this->organizationId;
    }
}
