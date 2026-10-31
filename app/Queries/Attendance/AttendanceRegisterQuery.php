<?php

namespace App\Queries\Attendance;

use App\Models\AttendancePunch;
use App\Models\LeaveRequest;
use App\Models\Member;
use App\Models\Organization;
use App\Queries\Structure\PeopleInViewQuery;
use App\Queries\Structure\PersonRef;
use App\Queries\Structure\ReportOptionsQuery;
use App\Support\Attendance\AttendanceCalendar;
use App\Support\Attendance\DaySchedule;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * The attendance register: one day of an organization, for the people the
 * viewer sees whom attendance expects (PeopleInViewQuery), or one person
 * and everyone below them. Who should be in, who is, who came late, who is
 * on leave or kaaj.
 *
 * A person's state comes from their punches first (in, at lunch, out),
 * then, with none: `off` on a day that expects no work, `on_kaaj` or
 * `on_leave` under approved leave covering the day, else `not_in`. So
 * someone who comes in despite their leave counts as present and as
 * expected, and present never exceeds expected.
 *
 * Punches and leave load in one query each.
 *
 * @phpstan-import-type Options from ReportOptionsQuery
 *
 * @phpstan-type Row array{member: array{id: int, name: string, designation: string|null}, state: string, in_at: string|null, lunch_start_at: string|null, lunch_end_at: string|null, out_at: string|null, late: bool, place: string|null, distance_m: int|null, note: string|null, leave: array{kind: string, place: string|null, starts_on: string, ends_on: string}|null}
 * @phpstan-type Summary array{staff: int, on_leave: int, on_kaaj: int, expected: int, present: int, late: int, not_in: int, outside_office: int, percentage: int|null}
 */
final class AttendanceRegisterQuery
{
    public const STATE_IN = 'in';

    public const STATE_AT_LUNCH = 'at_lunch';

    public const STATE_OUT = 'out';

    public const STATE_NOT_IN = 'not_in';

    public const STATE_ON_LEAVE = 'on_leave';

    public const STATE_ON_KAAJ = 'on_kaaj';

    public const STATE_OFF = 'off';

    /** Clock-in places that count as "outside office". */
    public const OUTSIDE_PLACES = [AttendancePunch::PLACE_OUTSIDE, AttendancePunch::PLACE_UNKNOWN];

    public function __construct(
        private readonly PeopleInViewQuery $people,
        private readonly ReportOptionsQuery $options,
    ) {}

    /**
     * @return array{date: string, schedule: array{date: string, is_workday: bool, holiday: string|null, expects_work: bool, start: string, end: string, late_after: string}, under: array{id: int, name: string}|null, summary: Summary, people: list<Row>, options: Options}
     */
    public function run(Authenticatable $viewer, int $organizationId, CarbonImmutable $date, ?Member $under = null): array
    {
        $day = AttendanceCalendar::for($organizationId)->day($date);
        $inView = $this->people->run($viewer, $organizationId);
        $listed = $this->people->run($viewer, $organizationId, $under, attending: true);
        $ids = $listed->modelKeys();

        $punches = AttendancePunch::query()
            ->whereIn('member_id', $ids)
            ->whereDate('work_date', $day->date->toDateString())
            ->get()
            ->groupBy('member_id');

        $leave = LeaveRequest::query()
            ->approved()
            ->whereIn('member_id', $ids)
            ->overlapping($day->date, $day->date)
            ->orderBy('starts_on')
            ->get()
            ->unique('member_id')
            ->keyBy('member_id');

        // Nobody is listed for a day before they were added, unless they
        // punched that day.
        $listed = $listed
            ->reject(fn (Member $member): bool => $member->created_at !== null
                && $member->created_at->toDateString() > $day->date->toDateString()
                && ! $punches->has($member->id))
            ->values();

        $rows = array_values($listed
            ->map(fn (Member $member): array => $this->row(
                $member,
                $punches->get($member->id, new Collection),
                $leave->get($member->id),
                $day,
            ))
            ->all());

        return [
            'date' => $day->date->toDateString(),
            'schedule' => $day->toArray(),
            'under' => $under === null ? null : ['id' => (int) $under->getKey(), 'name' => $under->name],
            'summary' => $this->summary($rows, $day),
            'people' => $rows,
            'options' => $this->options->run($viewer, $inView, withAttendance: true),
        ];
    }

    /**
     * @param  Collection<int, AttendancePunch>  $punches
     * @return Row
     */
    private function row(Member $member, Collection $punches, ?LeaveRequest $leave, DaySchedule $day): array
    {
        $at = fn (string $kind): ?AttendancePunch => $punches->firstWhere('kind', $kind);
        $in = $at(AttendancePunch::IN);
        $lunchStart = $at(AttendancePunch::LUNCH_START);
        $lunchEnd = $at(AttendancePunch::LUNCH_END);
        $out = $at(AttendancePunch::OUT);

        $state = match (true) {
            $out !== null => self::STATE_OUT,
            $lunchStart !== null && $lunchEnd === null => self::STATE_AT_LUNCH,
            $in !== null => self::STATE_IN,
            ! $day->expectsWork() => self::STATE_OFF,
            $leave?->kind === LeaveRequest::KIND_KAAJ => self::STATE_ON_KAAJ,
            $leave !== null => self::STATE_ON_LEAVE,
            default => self::STATE_NOT_IN,
        };

        return [
            'member' => PersonRef::of($member),
            'state' => $state,
            'in_at' => $in?->punched_at->toIso8601String(),
            'lunch_start_at' => $lunchStart?->punched_at->toIso8601String(),
            'lunch_end_at' => $lunchEnd?->punched_at->toIso8601String(),
            'out_at' => $out?->punched_at->toIso8601String(),
            'late' => $in !== null && $day->expectsWork() && $in->punched_at->gt($day->lateAfter),
            'place' => $in?->place,
            'distance_m' => $in?->distance_m,
            'note' => $in?->note,
            'leave' => $leave === null ? null : [
                'kind' => $leave->kind,
                'place' => $leave->place,
                'starts_on' => $leave->starts_on->toDateString(),
                'ends_on' => $leave->ends_on->toDateString(),
            ],
        ];
    }

    /**
     * @param  list<Row>  $rows
     * @return Summary
     */
    private function summary(array $rows, DaySchedule $day): array
    {
        $count = fn (callable $test): int => count(array_filter($rows, $test));

        $onLeave = $count(fn (array $row): bool => $row['state'] === self::STATE_ON_LEAVE);
        $onKaaj = $count(fn (array $row): bool => $row['state'] === self::STATE_ON_KAAJ);
        $expected = $day->expectsWork() ? count($rows) - $onLeave - $onKaaj : 0;
        $present = $count(fn (array $row): bool => $row['in_at'] !== null);

        return [
            'staff' => count($rows),
            'on_leave' => $onLeave,
            'on_kaaj' => $onKaaj,
            'expected' => $expected,
            'present' => $present,
            'late' => $count(fn (array $row): bool => $row['late']),
            'not_in' => $count(fn (array $row): bool => $row['state'] === self::STATE_NOT_IN),
            'outside_office' => $count(fn (array $row): bool => $row['in_at'] !== null && in_array($row['place'], self::OUTSIDE_PLACES, true)),
            'percentage' => $expected > 0 ? (int) round($present / $expected * 100) : null,
        ];
    }
}
