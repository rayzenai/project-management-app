<?php

namespace App\Queries\Work;

use App\Models\Member;
use App\Queries\Structure\PeopleInViewQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A team's work over a range: one row of figures per person in the
 * viewer's view (with `$under`, that person and everyone below them),
 * listed by name and never sorted by a figure here. `overdue` is what is
 * overdue now. `attendance` is each person's attendance totals over the
 * range, or null while the organization's attendance is off (or for
 * someone exempt).
 *
 * @phpstan-import-type Options from WorkFiltersQuery
 * @phpstan-import-type Totals from \App\Queries\Attendance\AttendanceSummaryQuery
 *
 * @phpstan-type TeamRow array{member: array{id: int, name: string, designation: ?string}, attendance: Totals|null, tasks_closed: int, tasks_started: int, overdue: int, commits: int, code_added: int, code_removed: int, pull_requests_merged: int}
 * @phpstan-type Team array{range: array{from: string, to: string}, organization_id: int, under: array{id: int, name: string}|null, people: list<TeamRow>, options: Options}
 */
final class TeamWorkQuery
{
    public function __construct(
        private readonly PeopleInViewQuery $people,
        private readonly WorkActivityQuery $activity,
        private readonly WorkFiltersQuery $filters,
        private readonly WorkAttendanceQuery $attendance,
    ) {}

    /**
     * @return Team
     */
    public function run(Authenticatable $viewer, int $organizationId, CarbonImmutable $from, CarbonImmutable $to, ?Member $under = null): array
    {
        $inView = $this->people->run($viewer, $organizationId);
        $people = $under === null ? $inView : $this->people->run($viewer, $organizationId, $under);
        $activity = $this->activity->forMembers($viewer, $people, $from, $to, withOverdue: true);
        $attendance = $this->attendance->range($organizationId, array_values(array_map(intval(...), $people->modelKeys())), $from, $to);

        $rows = [];

        foreach ($people as $member) {
            $work = $activity[(int) $member->getKey()];

            $rows[] = [
                'member' => WorkPerson::of($member),
                'attendance' => $attendance[(int) $member->getKey()] ?? null,
                'tasks_closed' => count($work['tasks_closed']) + $work['hidden_closed'],
                'tasks_started' => count($work['tasks_started']) + $work['hidden_started'],
                'overdue' => count($work['overdue'] ?? []) + ($work['hidden_overdue'] ?? 0),
                'commits' => $work['commits']['count'],
                'code_added' => $work['commits']['code_added'],
                'code_removed' => $work['commits']['code_removed'],
                'pull_requests_merged' => count($work['pull_requests_merged']) + $work['hidden_pull_requests'],
            ];
        }

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'organization_id' => $organizationId,
            'under' => $under === null ? null : ['id' => (int) $under->getKey(), 'name' => $under->name],
            'people' => $rows,
            'options' => $this->filters->options($viewer, $organizationId, $inView),
        ];
    }
}
