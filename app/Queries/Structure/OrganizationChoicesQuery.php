<?php

namespace App\Queries\Structure;

use App\Models\Organization;
use App\Support\WorkspaceAccess;
use App\Support\WorkspaceOrganization;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Which organization a page about one organization opens on, and which a
 * super-admin may switch between: the organogram, attendance and the work
 * reports. Everyone else only ever sees their own. Attendance pages offer
 * only organizations with attendance on. A super-admin's pages open on the
 * organization their workspace is switched to (WorkspaceOrganization) when
 * the page offers it.
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
     * The organization a super-admin's workspace is switched to, when this
     * page offers it; else the viewer's own organization; else, for a
     * super-admin who belongs to none, the first of `options`. Null when
     * there is none to open.
     */
    public function defaultId(?Authenticatable $viewer, bool $withAttendance = false): ?int
    {
        $chosen = WorkspaceOrganization::chosen($viewer);

        if ($chosen !== null && in_array($chosen, array_column($this->options($viewer, $withAttendance), 'id'), true)) {
            return $chosen;
        }

        $own = WorkspaceAccess::memberFor($viewer)?->organization_id;

        if ($own !== null) {
            return (int) $own;
        }

        return $this->options($viewer, $withAttendance)[0]['id'] ?? null;
    }

    /**
     * The sidebar's organization switch: the organizations and the one the
     * workspace is switched to (null: all). Only for a super-admin, and only
     * once there is more than one organization.
     *
     * @return array{organizations: list<array{id: int, name: string}>, current: int|null}|null
     */
    public function switcher(?Authenticatable $viewer): ?array
    {
        $options = $this->options($viewer);

        if (count($options) < 2) {
            return null;
        }

        $chosen = WorkspaceOrganization::chosen($viewer);

        return [
            'organizations' => $options,
            'current' => in_array($chosen, array_column($options, 'id'), true) ? $chosen : null,
        ];
    }
}
