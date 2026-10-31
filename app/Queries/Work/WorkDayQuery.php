<?php

namespace App\Queries\Work;

use App\Models\Member;
use App\Queries\Structure\PeopleInViewQuery;
use App\Queries\Structure\PersonRef;
use App\Queries\Structure\ReportOptionsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Today's work (or any day's), by person: what each person in the viewer's
 * view finished, started, committed and merged that day, listed by name.
 * The people come from PeopleInViewQuery (the organization for its admins,
 * otherwise the people below the viewer; with `$under`, that person and
 * everyone below them). `attendance` is the day's state from the register,
 * or null while the organization's attendance is off (or for someone
 * exempt).
 *
 * @phpstan-import-type WorkTask from WorkActivityQuery
 * @phpstan-import-type Code from WorkActivityQuery
 * @phpstan-import-type PullRequest from WorkActivityQuery
 * @phpstan-import-type Options from ReportOptionsQuery
 * @phpstan-import-type DayAttendance from WorkAttendanceQuery
 *
 * @phpstan-type DayRow array{member: array{id: int, name: string, designation: ?string}, attendance: DayAttendance|null, closed: list<WorkTask>, hidden_closed: int, started: list<WorkTask>, hidden_started: int, commits: Code, pull_requests_merged: list<PullRequest>, hidden_pull_requests: int, nothing_recorded: bool}
 * @phpstan-type DaySummary array{people: int, tasks_closed: int, tasks_started: int, commits: int, committers: int, pull_requests_merged: int, nothing_recorded: int}
 * @phpstan-type Day array{date: string, organization_id: int, under: array{id: int, name: string}|null, summary: DaySummary, people: list<DayRow>, options: Options}
 */
final class WorkDayQuery
{
    public function __construct(
        private readonly PeopleInViewQuery $people,
        private readonly WorkActivityQuery $activity,
        private readonly ReportOptionsQuery $options,
        private readonly WorkAttendanceQuery $attendance,
    ) {}

    /**
     * @return Day
     */
    public function run(Authenticatable $viewer, int $organizationId, CarbonImmutable $date, ?Member $under = null): array
    {
        $inView = $this->people->run($viewer, $organizationId);
        $people = $under === null ? $inView : $this->people->run($viewer, $organizationId, $under);
        $activity = $this->activity->forMembers($viewer, $people, $date, $date);
        $attendance = $this->attendance->day($viewer, $organizationId, $date, $under);

        $rows = [];
        $summary = ['people' => $people->count(), 'tasks_closed' => 0, 'tasks_started' => 0, 'commits' => 0, 'committers' => 0, 'pull_requests_merged' => 0, 'nothing_recorded' => 0];

        foreach ($people as $member) {
            $work = $activity[(int) $member->getKey()];
            $closed = count($work['tasks_closed']) + $work['hidden_closed'];
            $started = count($work['tasks_started']) + $work['hidden_started'];
            $merged = count($work['pull_requests_merged']) + $work['hidden_pull_requests'];
            $nothing = $closed === 0 && $started === 0 && $work['commits']['count'] === 0 && $merged === 0;

            $summary['tasks_closed'] += $closed;
            $summary['tasks_started'] += $started;
            $summary['commits'] += $work['commits']['count'];
            $summary['committers'] += $work['commits']['count'] > 0 ? 1 : 0;
            $summary['pull_requests_merged'] += $merged;
            $summary['nothing_recorded'] += $nothing ? 1 : 0;

            $rows[] = [
                'member' => PersonRef::of($member),
                'attendance' => $attendance[(int) $member->getKey()] ?? null,
                'closed' => $work['tasks_closed'],
                'hidden_closed' => $work['hidden_closed'],
                'started' => $work['tasks_started'],
                'hidden_started' => $work['hidden_started'],
                'commits' => $work['commits'],
                'pull_requests_merged' => $work['pull_requests_merged'],
                'hidden_pull_requests' => $work['hidden_pull_requests'],
                'nothing_recorded' => $nothing,
            ];
        }

        return [
            'date' => $date->toDateString(),
            'organization_id' => $organizationId,
            'under' => $under === null ? null : ['id' => (int) $under->getKey(), 'name' => $under->name],
            'summary' => $summary,
            'people' => $rows,
            'options' => $this->options->run($viewer, $inView),
        ];
    }
}
