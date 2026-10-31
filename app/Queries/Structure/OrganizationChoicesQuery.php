<?php

namespace App\Queries\Structure;

use App\Models\Organization;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Which organization a page about one organization opens on, and which a
 * super-admin may switch between: the organogram, attendance and the work
 * reports. Everyone else only ever sees their own. Attendance pages offer
 * only organizations with attendance on.
 */
final class OrganizationChoicesQuery
{
    /**
     * By name; empty for anyone but a super-admin.
     *
     * @return list<array{id: int, name: string}>
     */
    public function options(?Authenticatable $viewer, bool $withAttendance = false): array
    {
        if (! WorkspaceAccess::isSuperAdmin($viewer)) {
            return [];
        }

        return array_values(Organization::query()
            ->when($withAttendance, fn ($query) => $query->withAttendanceOn())
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (Organization $organization): array => ['id' => (int) $organization->id, 'name' => $organization->name])
            ->all());
    }

    /**
     * The viewer's own organization; for a super-admin, who belongs to none,
     * the first of `options`. Null when there is none to open.
     */
    public function defaultId(?Authenticatable $viewer, bool $withAttendance = false): ?int
    {
        $own = WorkspaceAccess::memberFor($viewer)?->organization_id;

        if ($own !== null) {
            return (int) $own;
        }

        return $this->options($viewer, $withAttendance)[0]['id'] ?? null;
    }
}
