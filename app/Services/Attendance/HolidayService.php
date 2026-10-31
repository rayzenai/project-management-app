<?php

namespace App\Services\Attendance;

use App\Models\Holiday;
use App\Models\Organization;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Adds, changes and removes an organization's holidays, one per date (the
 * caller checks {@see WorkspaceAccess::canManageAttendance()} and that the
 * holiday is the organization's). The request refuses a taken date; the
 * unique index answers a race the same way.
 */
class HolidayService
{
    public const DATE_TAKEN = 'That date is already a holiday.';

    /**
     * @param  array{date: string, name: string}  $attributes
     */
    public function add(Organization $organization, array $attributes): ServiceResult
    {
        try {
            $holiday = Holiday::query()->create(['organization_id' => $organization->id, ...$attributes]);
        } catch (UniqueConstraintViolationException) {
            return ServiceResult::failure(self::DATE_TAKEN, 422, ['date' => self::DATE_TAKEN]);
        }

        return ServiceResult::success($holiday, "{$holiday->name} added.");
    }

    /**
     * @param  array{date: string, name: string}  $attributes
     */
    public function change(Holiday $holiday, array $attributes): ServiceResult
    {
        try {
            $holiday->update($attributes);
        } catch (UniqueConstraintViolationException) {
            return ServiceResult::failure(self::DATE_TAKEN, 422, ['date' => self::DATE_TAKEN]);
        }

        return ServiceResult::success($holiday, "{$holiday->name} saved.");
    }

    public function remove(Holiday $holiday): ServiceResult
    {
        $holiday->delete();

        return ServiceResult::success(null, "{$holiday->name} removed.");
    }
}
