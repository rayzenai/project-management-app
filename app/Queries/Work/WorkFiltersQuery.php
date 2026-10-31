<?php

namespace App\Queries\Work;

use App\Models\Member;
use App\Models\Organization;
use App\Queries\Structure\PeopleInViewQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the work reports' filters offer: the organization they open on, the
 * organizations a super-admin may switch to, and the teams (someone and
 * everyone below them) a viewer may narrow to.
 *
 * @phpstan-type Options array{organizations: list<array{id: int, name: string}>, teams: list<array{id: int, name: string}>}
 */
final class WorkFiltersQuery
{
    public function __construct(private readonly PeopleInViewQuery $people) {}

    /**
     * The viewer's own organization; for a super-admin who has none, the
     * first by name. Null when there is none to open.
     */
    public function defaultOrganizationId(Authenticatable $viewer): ?int
    {
        $organizationId = WorkspaceAccess::memberFor($viewer)?->organization_id;

        if ($organizationId !== null) {
            return (int) $organizationId;
        }

        if (! WorkspaceAccess::isSuperAdmin($viewer)) {
            return null;
        }

        $first = Organization::query()->orderBy('name')->orderBy('id')->value('id');

        return $first === null ? null : (int) $first;
    }

    /**
     * Organizations: every one for a super-admin, none for anyone else (they
     * see their own). Teams: the people in view someone else in view
     * reports to, by name.
     *
     * @param  Collection<int, Member>|null  $inView  the viewer's people there, when the caller has them
     * @return Options
     */
    public function options(Authenticatable $viewer, int $organizationId, ?Collection $inView = null): array
    {
        $inView ??= $this->people->run($viewer, $organizationId);

        $leads = array_flip($inView->pluck('reports_to_id')->filter()->map(fn (mixed $id): int => (int) $id)->all());

        $teams = $inView
            ->filter(fn (Member $member): bool => isset($leads[(int) $member->getKey()]))
            ->map(fn (Member $member): array => ['id' => (int) $member->getKey(), 'name' => $member->name])
            ->values()
            ->all();

        $organizations = WorkspaceAccess::isSuperAdmin($viewer)
            ? Organization::query()
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name'])
                ->map(fn (Organization $organization): array => ['id' => (int) $organization->getKey(), 'name' => $organization->name])
                ->values()
                ->all()
            : [];

        return ['organizations' => array_values($organizations), 'teams' => array_values($teams)];
    }
}
