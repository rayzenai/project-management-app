<?php

namespace App\Queries\Attendance;

use App\Http\Resources\Attendance\PunchResource;
use App\Models\AttendancePunch;
use App\Support\Attendance\AttendanceCalendar;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The user's own day for the clock (the web's top-bar button, the app's
 * Today screen): today's hours, today's punches, the kinds that may come
 * next and where the day stands. Null when the user can't punch
 * ({@see WorkspaceAccess::canPunch()}).
 */
final class MyAttendanceQuery
{
    public const NOT_IN = 'not_in';

    public const IN = 'in';

    public const AT_LUNCH = 'at_lunch';

    public const OUT = 'out';

    /**
     * @return array{today: array{date: string, is_workday: bool, holiday: string|null, expects_work: bool, start: string, end: string, late_after: string}, punches: list<array{id: int, kind: string, at: string, work_date: string, source: string, place: string, place_name: string|null, distance_m: int|null, note: string|null}>, next: list<string>, state: string}|null
     */
    public function get(?Authenticatable $user): ?array
    {
        if (! WorkspaceAccess::canPunch($user)) {
            return null;
        }

        $member = WorkspaceAccess::memberFor($user);

        if ($member === null) {
            return null;
        }

        $today = CarbonImmutable::today();

        $punches = AttendancePunch::query()
            ->where('member_id', $member->id)
            ->whereDate('work_date', $today->toDateString())
            ->with('attendancePlace:id,name')
            ->orderBy('punched_at')
            ->orderBy('id')
            ->get();

        /** @var list<string> $done */
        $done = $punches->pluck('kind')->all();

        /** @var list<array{id: int, kind: string, at: string, work_date: string, source: string, place: string, place_name: string|null, distance_m: int|null, note: string|null}> $rows */
        $rows = array_values(PunchResource::collection($punches)->resolve());

        return [
            'today' => AttendanceCalendar::for((int) $member->organization_id)->day($today)->toArray(),
            'punches' => $rows,
            'next' => AttendancePunch::nextKinds($done),
            'state' => self::state($done),
        ];
    }

    /**
     * @param  list<string>  $done
     */
    private static function state(array $done): string
    {
        return match (true) {
            in_array(AttendancePunch::OUT, $done, true) => self::OUT,
            in_array(AttendancePunch::LUNCH_START, $done, true) && ! in_array(AttendancePunch::LUNCH_END, $done, true) => self::AT_LUNCH,
            in_array(AttendancePunch::IN, $done, true) => self::IN,
            default => self::NOT_IN,
        };
    }
}
