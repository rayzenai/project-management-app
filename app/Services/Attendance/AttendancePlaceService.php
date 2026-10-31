<?php

namespace App\Services\Attendance;

use App\Models\AttendancePlace;
use App\Models\Organization;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;

/**
 * Adds, changes and removes an organization's office places (the caller
 * checks {@see WorkspaceAccess::canManageAttendance()} and that the place is
 * the organization's). Punches already judged keep their verdict; a removed
 * place leaves them without a nearest place.
 */
class AttendancePlaceService
{
    /**
     * @param  array{name: string, latitude: float, longitude: float, radius_m: int}  $attributes
     */
    public function add(Organization $organization, array $attributes): ServiceResult
    {
        $place = AttendancePlace::query()->create(['organization_id' => $organization->id, ...$attributes]);

        return ServiceResult::success($place, "{$place->name} added.");
    }

    /**
     * @param  array{name: string, latitude: float, longitude: float, radius_m: int}  $attributes
     */
    public function change(AttendancePlace $place, array $attributes): ServiceResult
    {
        $place->update($attributes);

        return ServiceResult::success($place, "{$place->name} saved.");
    }

    public function remove(AttendancePlace $place): ServiceResult
    {
        $place->delete();

        return ServiceResult::success(null, "{$place->name} removed.");
    }
}
