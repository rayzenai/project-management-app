<?php

namespace App\Support\Attendance;

use App\Models\AttendanceSetting;
use App\Models\Holiday;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * An organization's working days and hours: its workdays, its holidays and
 * its seasonal hours (a season between two MM-DD dates, which may wrap the
 * year end, overrides the default hours). Every "who is expected" and "who
 * came late" answer starts here. Holidays load once per calendar.
 */
final class AttendanceCalendar
{
    /**
     * @param  array<string, string>  $holidays  Y-m-d => name
     */
    private function __construct(
        public readonly AttendanceSetting $settings,
        private readonly array $holidays,
    ) {}

    public static function for(Organization|int $organization): self
    {
        $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;

        $holidays = Holiday::query()
            ->where('organization_id', $organizationId)
            ->get(['date', 'name'])
            ->mapWithKeys(fn (Holiday $holiday): array => [$holiday->date->toDateString() => $holiday->name])
            ->all();

        return new self(AttendanceSetting::forOrganization($organizationId), $holidays);
    }

    public function day(CarbonInterface $date): DaySchedule
    {
        $day = CarbonImmutable::parse($date->toDateString());
        [$start, $end] = $this->hoursFor($day);

        $startsAt = $day->setTimeFromTimeString($start);

        return new DaySchedule(
            date: $day,
            isWorkday: in_array($day->dayOfWeekIso, array_map('intval', $this->settings->workdays), true),
            holiday: $this->holidays[$day->toDateString()] ?? null,
            start: $startsAt,
            end: $day->setTimeFromTimeString($end),
            lateAfter: $startsAt->addMinutes($this->settings->grace_minutes),
        );
    }

    /**
     * Every day from `$from` to `$to`, inclusive.
     *
     * @return list<DaySchedule>
     */
    public function days(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = [];

        for ($day = CarbonImmutable::parse($from->toDateString()); $day->lte($to); $day = $day->addDay()) {
            $days[] = $this->day($day);
        }

        return $days;
    }

    /**
     * @return array{0: string, 1: string} start and end, HH:MM
     */
    private function hoursFor(CarbonImmutable $day): array
    {
        $monthDay = $day->format('m-d');

        foreach ($this->settings->seasons as $season) {
            $wraps = $season['from'] > $season['to'];
            $inside = $wraps
                ? ($monthDay >= $season['from'] || $monthDay <= $season['to'])
                : ($monthDay >= $season['from'] && $monthDay <= $season['to']);

            if ($inside) {
                return [$season['start'], $season['end']];
            }
        }

        return [$this->settings->start_time, $this->settings->end_time];
    }
}
