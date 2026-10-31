<?php

namespace App\Support;

use App\Queries\Maintenance\MaintenanceQuery;
use App\Queries\Structure\OrganizationChoicesQuery;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Which of the office pages (organogram, attendance, leave and kaaj, work
 * reports, maintenance) the user may open, for the sidebar and the API's user payload,
 * with how many things the maintenance page lists for the organization it
 * opens on (MaintenanceQuery's cached count; 0 when it is not offered).
 * Every answer comes from WorkspaceAccess; the pages check again.
 */
final class OfficeLinks
{
    /**
     * @return array{organogram: bool, attendance: bool, attendance_settings: bool, leave: bool, team_work: bool, my_work_member_id: int|null, maintenance: bool, maintenance_count: int}
     */
    public static function for(?Authenticatable $user): array
    {
        $superAdmin = WorkspaceAccess::isSuperAdmin($user);
        $member = WorkspaceAccess::memberFor($user);
        $organizationId = $member !== null && $member->is_active ? $member->organization_id : null;
        $maintenanceId = $superAdmin ? app(OrganizationChoicesQuery::class)->defaultId($user) : $organizationId;
        $maintenance = $maintenanceId !== null && WorkspaceAccess::canViewMaintenance($user, $maintenanceId);
        $register = $superAdmin
            ? WorkspaceAccess::attendanceEnabledAnywhere()
            : WorkspaceAccess::canViewAttendanceRegister($user, $organizationId);

        return [
            'organogram' => $superAdmin || WorkspaceAccess::canViewOrganogram($user, $organizationId),
            'attendance' => $register,
            'attendance_settings' => $superAdmin || WorkspaceAccess::canManageAttendance($user, $organizationId),
            'leave' => WorkspaceAccess::canPunch($user) || $register,
            'team_work' => $superAdmin || WorkspaceAccess::canViewTeamWork($user, $organizationId),
            'my_work_member_id' => $organizationId === null ? null : (int) $member?->getKey(),
            'maintenance' => $superAdmin || $maintenance,
            'maintenance_count' => $maintenance ? app(MaintenanceQuery::class)->count((int) $maintenanceId) : 0,
        ];
    }
}
