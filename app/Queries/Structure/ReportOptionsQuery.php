<?php

namespace App\Queries\Structure;

use App\Models\Member;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * The filters a list report offers (the attendance register, today's work,
 * a team's week): the organizations a super-admin may switch between, and
 * the teams to narrow to, each a person in view with someone in view
 * reporting to them, by name.
 *
 * @phpstan-type Options array{organizations: list<array{id: int, name: string}>, teams: list<array{id: int, name: string, designation: string|null}>}
 */
final class ReportOptionsQuery
{
    public function __construct(private readonly OrganizationChoicesQuery $organizations) {}

    /**
     * @param  Collection<int, Member>  $inView  the viewer's people there (PeopleInViewQuery)
     * @return Options
     */
    public function run(Authenticatable $viewer, Collection $inView, bool $withAttendance = false): array
    {
        $heads = array_flip($inView->pluck('reports_to_id')->filter()->map(fn (mixed $id): int => (int) $id)->all());

        return [
            'organizations' => $this->organizations->options($viewer, $withAttendance),
            'teams' => array_values($inView
                ->filter(fn (Member $member): bool => isset($heads[(int) $member->getKey()]))
                ->map(fn (Member $member): array => PersonRef::of($member))
                ->all()),
        ];
    }
}
