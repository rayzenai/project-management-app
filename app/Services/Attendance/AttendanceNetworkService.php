<?php

namespace App\Services\Attendance;

use App\Models\AttendanceNetwork;
use App\Models\Organization;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;

/**
 * Adds, changes and removes an organization's office network ranges (the
 * caller checks {@see WorkspaceAccess::canManageAttendance()} and that the
 * range is the organization's). Punches already judged keep their verdict.
 */
class AttendanceNetworkService
{
    /**
     * @param  array{label: string, cidr: string}  $attributes
     */
    public function add(Organization $organization, array $attributes): ServiceResult
    {
        $network = AttendanceNetwork::query()->create(['organization_id' => $organization->id, ...$attributes]);

        return ServiceResult::success($network, "{$network->label} added.");
    }

    /**
     * @param  array{label: string, cidr: string}  $attributes
     */
    public function change(AttendanceNetwork $network, array $attributes): ServiceResult
    {
        $network->update($attributes);

        return ServiceResult::success($network, "{$network->label} saved.");
    }

    public function remove(AttendanceNetwork $network): ServiceResult
    {
        $network->delete();

        return ServiceResult::success(null, "{$network->label} removed.");
    }
}
