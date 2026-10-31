<?php

namespace App\Queries\Attendance;

use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\LeaveRequest;
use App\Models\Member;
use App\Queries\Structure\PeopleInViewQuery;
use App\Support\Attendance\AttendanceCalendar;
use App\Support\Attendance\DaySchedule;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Attendance over a range of days, per person: what the work reports show
 * in their attendance columns and the summary endpoint returns. A few
 * queries in all (the people, each organization's calendar, the punches,
 * the leave), never one per person or per day.
 *
 * Only days up to today count. A working day counts while the person's
 * organization has attendance on (from the day it was turned on):
 *
 * - expected: a working day the person isn't exempt, unless approved leave
 *   or kaaj covers it and they didn't clock in (someone who comes in
 *   despite their leave is expected and present, as on the register);
 * - present, late: working days with a clock-in (after `lateAfter`);
 * - leave, kaaj: working days under approved leave or kaaj without a
 *   clock-in;
 * - minutes worked: every day with a clock-in, clock-in to clock-out minus
 *   lunch (an unfinished lunch runs to the clock-out); a day without a
 *   clock-out runs to the scheduled end, or to now today, and a past one
 *   counts in `no_clock_out_days`;
 * - outside punches: punches of any kind outside the office or from an
 *   unknown place;
 * - usual in: the median clock-in time, HH:MM.
 *
 * @phpstan-type Totals array{days_expected: int, days_present: int, days_late: int, minutes_worked: int, no_clock_out_days: int, outside_punches: int, leave_days: int, kaaj_days: int, usual_in: string|null}
 */
final class AttendanceSummaryQuery
{
    public function __construct(private readonly PeopleInViewQuery $people) {}

    /**
     * One row per person the viewer sees (PeopleInViewQuery, exempt people
     * left out, as on the register), or one person and everyone below them.
     *
     * @return list<array{member: array{id: int, name: string, designation: string|null}, days_expected: int, days_present: int, days_late: int, minutes_worked: int, no_clock_out_days: int, outside_punches: int, leave_days: int, kaaj_days: int, usual_in: string|null}>
     */
    public function run(Authenticatable $viewer, int $organizationId, CarbonImmutable $from, CarbonImmutable $to, ?Member $under = null): array
    {
        $people = $this->people->run($viewer, $organizationId, $under)
            ->reject(fn (Member $member): bool => $member->attendance_exempt)
            ->values();
        $totals = $this->forMembers(array_values(array_map(intval(...), $people->modelKeys())), $from, $to);

        return array_values($people
            ->map(fn (Member $member): array => ['member' => AttendanceRegisterQuery::person($member), ...$totals[(int) $member->id]])
            ->all());
    }

    /**
     * @param  list<int>  $memberIds
     * @return array<int, Totals>
     */
    public function forMembers(array $memberIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $memberIds)));
        $totals = array_fill_keys($ids, self::empty());
        $now = CarbonImmutable::now();
        $last = $to->min($now->startOfDay())->startOfDay();
        $from = $from->startOfDay();

        if ($ids === [] || $last->lt($from)) {
            return $totals;
        }

        $members = Member::withTrashed()->whereIn('id', $ids)->get(['id', 'organization_id', 'attendance_exempt']);

        /** @var array<int, list<DaySchedule>> $days */
        $days = [];
        /** @var array<int, string|null> $countsFrom Y-m-d attendance counts from, null when off */
        $countsFrom = [];

        foreach ($members->pluck('organization_id')->filter()->unique() as $organizationId) {
            $calendar = AttendanceCalendar::for((int) $organizationId);
            $days[(int) $organizationId] = $calendar->days($from, $last);
            $countsFrom[(int) $organizationId] = $this->countsFrom($calendar->settings);
        }

        $punches = AttendancePunch::query()
            ->whereIn('member_id', $ids)
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $last->toDateString())
            ->get(['member_id', 'work_date', 'kind', 'punched_at', 'place'])
            ->groupBy(fn (AttendancePunch $punch): string => $punch->member_id.'|'.$punch->work_date->toDateString());

        $leave = LeaveRequest::query()
            ->approved()
            ->whereIn('member_id', $ids)
            ->overlapping($from, $last)
            ->get(['member_id', 'kind', 'starts_on', 'ends_on'])
            ->groupBy('member_id');

        foreach ($members as $member) {
            $organizationId = (int) $member->organization_id;

            if (! isset($days[$organizationId])) {
                continue;
            }

            $totals[(int) $member->id] = $this->totals(
                $member,
                $days[$organizationId],
                $countsFrom[$organizationId],
                $punches,
                $leave->get($member->id, new Collection),
                $now,
            );
        }

        return $totals;
    }

    /**
     * @param  list<DaySchedule>  $days
     * @param  Collection<array-key, EloquentCollection<int, AttendancePunch>>  $punches
     * @param  Collection<int, LeaveRequest>  $leave
     * @return Totals
     */
    private function totals(Member $member, array $days, ?string $countsFrom, Collection $punches, Collection $leave, CarbonImmutable $now): array
    {
        $totals = self::empty();
        $seconds = 0;
        $clockIns = [];
        $today = $now->toDateString();

        foreach ($days as $day) {
            $date = $day->date->toDateString();
            $dayPunches = $punches->get($member->id.'|'.$date, new EloquentCollection);
            $in = $dayPunches->firstWhere('kind', AttendancePunch::IN);
            $workday = $day->expectsWork() && $countsFrom !== null && $date >= $countsFrom;
            $covering = $leave->first(fn (LeaveRequest $request): bool => $request->covers($day->date));

            $totals['outside_punches'] += $dayPunches
                ->filter(fn (AttendancePunch $punch): bool => in_array($punch->place, AttendanceRegisterQuery::OUTSIDE_PLACES, true))
                ->count();

            if ($in === null) {
                if ($workday && $covering !== null) {
                    $totals[$covering->kind === LeaveRequest::KIND_KAAJ ? 'kaaj_days' : 'leave_days']++;
                } elseif ($workday && ! $member->attendance_exempt) {
                    $totals['days_expected']++;
                }

                continue;
            }

            if ($workday) {
                $totals['days_expected'] += $member->attendance_exempt ? 0 : 1;
                $totals['days_present']++;
                $totals['days_late'] += $in->punched_at->gt($day->lateAfter) ? 1 : 0;
            }

            $out = $dayPunches->firstWhere('kind', AttendancePunch::OUT);

            if ($out === null && $date < $today) {
                $totals['no_clock_out_days']++;
            }

            $seconds += $this->secondsWorked(
                $in->punched_at,
                $out->punched_at ?? ($date === $today ? $now : $day->end),
                $dayPunches->firstWhere('kind', AttendancePunch::LUNCH_START)?->punched_at,
                $dayPunches->firstWhere('kind', AttendancePunch::LUNCH_END)?->punched_at,
            );
            $clockIns[] = $in->punched_at->hour * 60 + $in->punched_at->minute;
        }

        $totals['minutes_worked'] = intdiv($seconds, 60);
        $totals['usual_in'] = self::median($clockIns);

        return $totals;
    }

    /**
     * Clock-in to the end of the day, less lunch (an unfinished lunch runs
     * to the end); never negative.
     */
    private function secondsWorked(CarbonImmutable $in, CarbonImmutable $end, ?CarbonImmutable $lunchStart, ?CarbonImmutable $lunchEnd): int
    {
        $worked = $end->getTimestamp() - $in->getTimestamp();

        if ($worked <= 0) {
            return 0;
        }

        if ($lunchStart !== null) {
            $from = max($lunchStart->getTimestamp(), $in->getTimestamp());
            $until = min(($lunchEnd ?? $end)->getTimestamp(), $end->getTimestamp());
            $worked -= max(0, $until - $from);
        }

        return max(0, $worked);
    }

    /**
     * The day attendance counts from (the day it was turned on), or null
     * while it is off.
     */
    private function countsFrom(AttendanceSetting $settings): ?string
    {
        if (! $settings->enabled) {
            return null;
        }

        return $settings->enabled_at?->toDateString() ?? '0000-00-00';
    }

    /**
     * @param  list<int>  $minutes  minutes after midnight
     */
    private static function median(array $minutes): ?string
    {
        if ($minutes === []) {
            return null;
        }

        sort($minutes);
        $middle = intdiv(count($minutes), 2);
        $median = count($minutes) % 2 === 1
            ? $minutes[$middle]
            : intdiv($minutes[$middle - 1] + $minutes[$middle], 2);

        return sprintf('%02d:%02d', intdiv($median, 60), $median % 60);
    }

    /**
     * @return Totals
     */
    private static function empty(): array
    {
        return [
            'days_expected' => 0,
            'days_present' => 0,
            'days_late' => 0,
            'minutes_worked' => 0,
            'no_clock_out_days' => 0,
            'outside_punches' => 0,
            'leave_days' => 0,
            'kaaj_days' => 0,
            'usual_in' => null,
        ];
    }
}
