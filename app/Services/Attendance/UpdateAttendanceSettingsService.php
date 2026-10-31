<?php

namespace App\Services\Attendance;

use App\Models\AttendanceSetting;
use App\Models\Organization;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;

/**
 * Saves an organization's working days, hours, seasons and grace (whoever
 * administers it, the caller's check:
 * {@see WorkspaceAccess::canManageAttendance()}). An organization whose
 * attendance is still off gets its row now, still off, so its hours are
 * ready before a super-admin turns it on.
 */
class UpdateAttendanceSettingsService
{
    /**
     * @param  array{workdays: list<int>, start_time: string, end_time: string, seasons: list<array{name: string, from: string, to: string, start: string, end: string}>, grace_minutes: int}  $settings
     */
    public function execute(Organization $organization, array $settings): ServiceResult
    {
        $setting = AttendanceSetting::query()->firstOrNew(['organization_id' => $organization->id]);
        $setting->fill($settings)->save();

        return ServiceResult::success($setting, 'Hours saved.');
    }
}
