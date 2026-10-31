<?php

namespace App\Services\Attendance;

use App\Models\AttendanceSetting;
use App\Models\Organization;
use App\Models\User;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;

/**
 * Turns an organization's attendance on or off (a super-admin's switch, the
 * caller's check: {@see WorkspaceAccess::canEnableAttendance()}). The first
 * time it makes the settings row with the defaults; turning it on records
 * who and when, and turning it off keeps that record and every punch.
 */
class EnableAttendanceService
{
    public function execute(Organization $organization, bool $enabled, User $actor): ServiceResult
    {
        $setting = AttendanceSetting::query()->firstOrNew(
            ['organization_id' => $organization->id],
            AttendanceSetting::defaults(),
        );

        if ($enabled && ! $setting->enabled) {
            $setting->enabled_by = (int) $actor->getKey();
            $setting->enabled_at = now()->toImmutable();
        }

        $setting->enabled = $enabled;
        $setting->save();

        WorkspaceAccess::flush();

        return ServiceResult::success(
            $setting,
            $enabled ? "Attendance is on for {$organization->name}." : "Attendance is off for {$organization->name}.",
        );
    }
}
