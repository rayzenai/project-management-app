<?php

namespace App\Support\Attendance;

use Carbon\CarbonImmutable;

/**
 * One day of an organization's calendar: whether it is a working day, the
 * holiday that closes it, and its hours (with the season applied).
 */
final readonly class DaySchedule
{
    public function __construct(
        public CarbonImmutable $date,
        public bool $isWorkday,
        public ?string $holiday,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public CarbonImmutable $lateAfter,
    ) {}

    /** A working day the office is open: people are expected. */
    public function expectsWork(): bool
    {
        return $this->isWorkday && $this->holiday === null;
    }

    /**
     * @return array{date: string, is_workday: bool, holiday: string|null, expects_work: bool, start: string, end: string, late_after: string}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date->toDateString(),
            'is_workday' => $this->isWorkday,
            'holiday' => $this->holiday,
            'expects_work' => $this->expectsWork(),
            'start' => $this->start->format('H:i'),
            'end' => $this->end->format('H:i'),
            'late_after' => $this->lateAfter->format('H:i'),
        ];
    }
}
