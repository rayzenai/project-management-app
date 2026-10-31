<?php

namespace App\Queries\Work;

use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * One person's work over a range: the tasks they finished and started,
 * what is overdue for them now, their commits and merged pull requests.
 * The caller's FormRequest checks WorkspaceAccess::canViewPersonOf first.
 * `attendance` is null here; the controller fills it when the person's
 * organization has attendance on.
 *
 * @phpstan-import-type WorkTask from WorkActivityQuery
 * @phpstan-import-type OverdueTask from WorkActivityQuery
 * @phpstan-import-type Code from WorkActivityQuery
 * @phpstan-import-type PullRequest from WorkActivityQuery
 *
 * @phpstan-type Counts array{closed: int, started: int, overdue: int, hidden_closed: int, hidden_started: int, hidden_overdue: int}
 * @phpstan-type PersonWork array{member: array{id: int, name: string, designation: ?string, organization_id: ?int, reports_to: array{id: int, name: string}|null}, range: array{from: string, to: string}, attendance: null, tasks: array{closed: list<WorkTask>, started: list<WorkTask>, overdue: list<OverdueTask>, counts: Counts}, code: Code, pull_requests_merged: list<PullRequest>, hidden_pull_requests: int}
 */
final class PersonWorkQuery
{
    public function __construct(private readonly WorkActivityQuery $activity) {}

    /**
     * @return PersonWork
     */
    public function run(Authenticatable $viewer, Member $member, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $member->loadMissing(['designation:id,name', 'reportsTo:id,name']);
        $work = $this->activity->forMembers($viewer, [$member], $from, $to, withOverdue: true)[(int) $member->getKey()];
        $overdue = $work['overdue'] ?? [];
        $hiddenOverdue = $work['hidden_overdue'] ?? 0;
        $boss = $member->reportsTo;

        return [
            'member' => [
                ...WorkPerson::of($member),
                'organization_id' => $member->organization_id,
                'reports_to' => $boss === null ? null : ['id' => (int) $boss->getKey(), 'name' => $boss->name],
            ],
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'attendance' => null,
            'tasks' => [
                'closed' => $work['tasks_closed'],
                'started' => $work['tasks_started'],
                'overdue' => $overdue,
                'counts' => [
                    'closed' => count($work['tasks_closed']) + $work['hidden_closed'],
                    'started' => count($work['tasks_started']) + $work['hidden_started'],
                    'overdue' => count($overdue) + $hiddenOverdue,
                    'hidden_closed' => $work['hidden_closed'],
                    'hidden_started' => $work['hidden_started'],
                    'hidden_overdue' => $hiddenOverdue,
                ],
            ],
            'code' => $work['commits'],
            'pull_requests_merged' => $work['pull_requests_merged'],
            'hidden_pull_requests' => $work['hidden_pull_requests'],
        ];
    }
}
