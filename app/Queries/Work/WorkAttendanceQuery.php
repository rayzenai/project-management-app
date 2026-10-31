<?php

namespace App\Queries\Work;

use App\Models\Member;
use App\Queries\Attendance\AttendanceRegisterQuery;
use App\Queries\Attendance\AttendanceSummaryQuery;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The attendance the work reports show beside each person, read through
 * the attendance queries (never a query of its own): a day's state from
 * the register, a range's totals from the summary. Empty while the
 * organization's attendance is off, so the reports show none.
 *
 * @phpstan-import-type Totals from AttendanceSummaryQuery
 *
 * @phpstan-type DayAttendance array{state: string, in_at: string|null, late: bool, place: string|null}
 */
final class WorkAttendanceQuery
{
    public function __construct(
        private readonly AttendanceRegisterQuery $register,
        private readonly AttendanceSummaryQuery $summary,
    ) {}

    /**
     * @return array<int, DayAttendance> by member id
     */
    public function day(Authenticatable $viewer, int $organizationId, CarbonImmutable $date, ?Member $under = null): array
    {
        if (! WorkspaceAccess::attendanceEnabled($organizationId)) {
            return [];
        }

        $byMember = [];

        foreach ($this->register->run($viewer, $organizationId, $date, $under)['people'] as $row) {
            $byMember[$row['member']['id']] = [
                'state' => $row['state'],
                'in_at' => $row['in_at'],
                'late' => $row['late'],
                'place' => $row['place'],
            ];
        }

        return $byMember;
    }

    /**
     * @param  list<int>  $memberIds
     * @return array<int, Totals> by member id
     */
    public function range(int $organizationId, array $memberIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! WorkspaceAccess::attendanceEnabled($organizationId) || $memberIds === []) {
            return [];
        }

        return $this->summary->forMembers($memberIds, $from, $to);
    }
}
