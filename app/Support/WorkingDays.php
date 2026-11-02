<?php

namespace App\Support;

use App\Support\Attendance\AttendanceCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Which days an organization works. With attendance on it is the attendance
 * calendar (its workdays and holidays); otherwise, and for a person in no
 * organization, Sunday to Friday with no holidays. The calendar is built
 * once per instance.
 */
final class WorkingDays
{
    /** ISO weekdays: Sunday to Friday. */
    private const DEFAULT_WORKDAYS = [7, 1, 2, 3, 4, 5];

    /** The week is read Sunday first. */
    private const WEEK_ORDER = [7, 1, 2, 3, 4, 5, 6];

    private ?AttendanceCalendar $calendar = null;

    private function __construct(private readonly ?int $organizationId) {}

    public static function forOrganization(?int $organizationId): self
    {
        return new self($organizationId);
    }

    public static function forUser(?Authenticatable $user): self
    {
        $organizationId = WorkspaceAccess::memberFor($user)?->organization_id;

        return new self($organizationId === null ? null : (int) $organizationId);
    }

    public function isWorkingDay(CarbonInterface $date): bool
    {
        if ($this->organizationId !== null && WorkspaceAccess::attendanceEnabled($this->organizationId)) {
            $this->calendar ??= AttendanceCalendar::for($this->organizationId);

            return $this->calendar->day($date)->expectsWork();
        }

        return in_array($date->dayOfWeekIso, self::DEFAULT_WORKDAYS, true);
    }

    /** The first working day strictly after `$after`; gives up after 31 days. */
    public function nextWorkingDay(CarbonInterface $after): CarbonImmutable
    {
        $day = CarbonImmutable::parse($after->toDateString())->addDay();

        for ($tries = 0; $tries < 31; $tries++) {
            if ($this->isWorkingDay($day)) {
                return $day;
            }

            $day = $day->addDay();
        }

        return CarbonImmutable::parse($after->toDateString())->addDay();
    }

    /**
     * The latest date on or before `$date` that falls on the week's first
     * weekday. The week is read Sunday first and starts on the first weekday
     * that is a workday (the organization's configured workdays with
     * attendance on, else Sunday to Friday). Holidays never move it.
     */
    public function weekStart(CarbonInterface $date): CarbonImmutable
    {
        $workdays = $this->organizationId !== null && WorkspaceAccess::attendanceEnabled($this->organizationId)
            ? array_map('intval', ($this->calendar ??= AttendanceCalendar::for($this->organizationId))->settings->workdays)
            : self::DEFAULT_WORKDAYS;

        $first = 7;

        foreach (self::WEEK_ORDER as $weekday) {
            if (in_array($weekday, $workdays, true)) {
                $first = $weekday;
                break;
            }
        }

        $day = CarbonImmutable::parse($date->toDateString());

        return $day->subDays(($day->dayOfWeekIso - $first + 7) % 7);
    }
}
