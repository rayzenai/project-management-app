<?php

namespace App\Http\Requests\Structure;

use App\Models\Organization;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening an organization's organogram: super-admins and its active members
 * (WorkspaceAccess::canViewOrganogram). The API names the organization in
 * the path; the web page shows the viewer's own, and a super-admin picks
 * one with `?organization=` (none picked: the page offers a choice).
 */
class OrganogramRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = $this->organizationId();

        if ($organizationId === null) {
            return ! $this->route('organization') instanceof Organization && WorkspaceAccess::isSuperAdmin($this->user());
        }

        return WorkspaceAccess::canViewOrganogram($this->user(), $organizationId);
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
     * the viewer's own. Null only for a super-admin who has not picked.
     */
    public function organizationId(): ?int
    {
        $bound = $this->route('organization');

        if ($bound instanceof Organization) {
            return (int) $bound->getKey();
        }

        if (WorkspaceAccess::isSuperAdmin($this->user())) {
            $picked = $this->query('organization');

            if (is_numeric($picked)) {
                return (int) $picked;
            }
        }

        $own = WorkspaceAccess::memberFor($this->user())?->organization_id;

        return $own === null ? null : (int) $own;
    }
}
