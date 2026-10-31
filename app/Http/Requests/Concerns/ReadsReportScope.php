<?php

namespace App\Http\Requests\Concerns;

use App\Models\Member;
use App\Models\Organization;
use App\Queries\Structure\OrganizationChoicesQuery;
use App\Queries\Structure\PeopleInViewQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Whose rows a list report shows (the attendance register and totals,
 * today's work, a team's week): an organization (the API's
 * `{organization}`, else `?organization=`, else OrganizationChoicesQuery's
 * default) and optionally `?under=`, someone of it whose team (them and
 * everyone below them) narrows the list. Each report says who may see an
 * organization's list; `under` must pass WorkspaceAccess::canViewPersonOf.
 * An unknown or malformed `under` is a 403 like one outside the viewer's
 * view, so the filter never confirms who exists.
 */
trait ReadsReportScope
{
    private ?int $scopeOrganizationId = null;

    private ?Member $scopeUnder = null;

    /**
     * Whether the user may see this organization's list.
     */
    abstract protected function maySeeOrganization(Authenticatable $user, int $organizationId): bool;

    /**
     * Whether the report is about attendance: a super-admin then opens, and
     * switches between, organizations with attendance on.
     */
    protected function aboutAttendance(): bool
    {
        return false;
    }

    protected function authorizeScope(OrganizationChoicesQuery $organizations, PeopleInViewQuery $people): bool
    {
        $user = $this->user();
        $organizationId = $user === null ? null : $this->requestedOrganizationId($organizations, $user);

        if ($user === null || $organizationId === null || ! $this->maySeeOrganization($user, $organizationId)) {
            return false;
        }

        $under = $this->query('under');

        if ($under !== null && $under !== '') {
            $id = self::positiveInt($under);
            $member = $id === null ? null : $people->find($organizationId, $id);

            if ($member === null || ! WorkspaceAccess::canViewPersonOf($user, $member)) {
                return false;
            }

            $this->scopeUnder = $member;
        }

        $this->scopeOrganizationId = $organizationId;

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function scopeRules(): array
    {
        return ['organization' => ['nullable', 'integer', 'exists:organizations,id']];
    }

    public function organizationId(): int
    {
        return (int) $this->scopeOrganizationId;
    }

    public function under(): ?Member
    {
        return $this->scopeUnder;
    }

    private function requestedOrganizationId(OrganizationChoicesQuery $organizations, Authenticatable $user): ?int
    {
        $routed = $this->route('organization');

        if ($routed instanceof Organization) {
            return (int) $routed->getKey();
        }

        $asked = $this->query('organization');

        if ($asked !== null && $asked !== '') {
            return self::positiveInt($asked);
        }

        return $organizations->defaultId($user, $this->aboutAttendance());
    }

    private static function positiveInt(mixed $value): ?int
    {
        $id = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        return $id === false ? null : $id;
    }
}
