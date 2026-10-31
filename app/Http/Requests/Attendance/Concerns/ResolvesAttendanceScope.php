<?php

namespace App\Http\Requests\Attendance\Concerns;

use App\Models\Member;
use App\Models\Organization;
use App\Queries\Attendance\AttendanceRegisterQuery;
use App\Support\WorkspaceAccess;

/**
 * Whose attendance a register or summary request covers, and whether the
 * user may see it: the organization (the route's on the API; `?organization=`
 * or the user's own on the web, a super-admin's first with attendance on),
 * which needs WorkspaceAccess::canViewAttendanceRegister, and optionally
 * `?under=` someone of that organization the user may see
 * (WorkspaceAccess::canViewPersonOf), else a 403 that never says whether
 * they exist.
 */
trait ResolvesAttendanceScope
{
    private ?int $scopeOrganizationId = null;

    private ?Member $scopeUnder = null;

    private function authorizeScope(AttendanceRegisterQuery $query): bool
    {
        $user = $this->user();
        $route = $this->route('organization');
        $asked = $this->input('organization');

        $this->scopeOrganizationId = match (true) {
            $route instanceof Organization => (int) $route->id,
            $route !== null => is_numeric($route) ? (int) $route : null,
            is_numeric($asked) => (int) $asked,
            default => WorkspaceAccess::memberFor($user)->organization_id ?? ($query->organizationOptions($user)[0]['id'] ?? null),
        };

        if (! WorkspaceAccess::canViewAttendanceRegister($user, $this->scopeOrganizationId)) {
            return false;
        }

        $under = $this->input('under');

        if (! is_numeric($under)) {
            return true; // none, or the rules answer
        }

        $member = Member::query()->find((int) $under);

        if ($member === null
            || (int) $member->organization_id !== $this->scopeOrganizationId
            || ! WorkspaceAccess::canViewPersonOf($user, $member)) {
            return false;
        }

        $this->scopeUnder = $member;

        return true;
    }

    public function organizationId(): int
    {
        return (int) $this->scopeOrganizationId;
    }

    public function under(): ?Member
    {
        return $this->scopeUnder;
    }
}
