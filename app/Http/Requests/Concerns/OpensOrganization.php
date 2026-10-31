<?php

namespace App\Http\Requests\Concerns;

use App\Models\Organization;
use App\Queries\Structure\OrganizationChoicesQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Validation\Rule;

/**
 * A page about one organization: the API names it in the path; the web page
 * opens OrganizationChoicesQuery's default, and a super-admin picks another
 * with `?organization=` (anyone else's pick is ignored). The request's
 * authorize() opens it, then asks whether the user may see it.
 */
trait OpensOrganization
{
    private ?int $organizationId = null;

    /**
     * The organization the page is about; null only for a super-admin when
     * there is no organization at all.
     */
    protected function openOrganization(OrganizationChoicesQuery $organizations): ?int
    {
        $user = $this->user();
        $routed = $this->route('organization');
        $picked = $this->query('organization');

        return $this->organizationId = match (true) {
            $routed instanceof Organization => (int) $routed->getKey(),
            WorkspaceAccess::isSuperAdmin($user) && is_numeric($picked) => (int) $picked,
            default => $organizations->defaultId($user),
        };
    }

    /**
     * A super-admin with no organization to show still opens the page empty.
     */
    protected function opensNothing(): bool
    {
        return $this->organizationId === null
            && ! $this->route('organization') instanceof Organization
            && WorkspaceAccess::isSuperAdmin($this->user());
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

    public function organizationId(): ?int
    {
        return $this->organizationId;
    }
}
