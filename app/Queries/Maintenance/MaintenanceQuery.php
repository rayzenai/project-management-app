<?php

namespace App\Queries\Maintenance;

use App\Models\AttendanceNetwork;
use App\Models\AttendancePlace;
use App\Models\AttendanceSetting;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Task;
use App\Queries\Structure\PersonRef;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What needs looking at in an organization, for whoever administers it
 * (WorkspaceAccess::canViewMaintenance). Every list is empty when all is
 * well:
 *
 * - its active projects with no active manager (`manager` is the
 *   deactivated one, or null), with no active person on the team, with
 *   open tasks nobody holds or only deactivated people hold (`count`), and
 *   with no activity for QUIET_DAYS (`last_activity_at`, the project's
 *   creation when it has none);
 * - its programs with no active manager;
 * - its active people on no active project's team, with no designation,
 *   reporting to someone deactivated (`reports_to`), outside the organogram
 *   (reporting to no one, and no one reporting to them), and with no login;
 * - attendance switched on with no office network or office place to judge
 *   punches by.
 *
 * A constant number of queries, however big the organization.
 */
final class MaintenanceQuery
{
    public const QUIET_DAYS = 30;

    /**
     * @return array{
     *     organization: array{id: int, name: string},
     *     quiet_days: int,
     *     projects_without_manager: list<array{slug: string, label: string, manager: array{id: int, name: string, designation: string|null}|null}>,
     *     projects_without_people: list<array{slug: string, label: string}>,
     *     unassigned_tasks: list<array{slug: string, label: string, count: int}>,
     *     tasks_with_inactive_people: list<array{slug: string, label: string, count: int}>,
     *     quiet_projects: list<array{slug: string, label: string, last_activity_at: string}>,
     *     programs_without_manager: list<array{slug: string, name: string, manager: array{id: int, name: string, designation: string|null}|null}>,
     *     people_off_projects: list<array{id: int, name: string, designation: string|null}>,
     *     people_without_designation: list<array{id: int, name: string, designation: string|null}>,
     *     people_reporting_to_inactive: list<array{id: int, name: string, designation: string|null, reports_to: array{id: int, name: string, designation: string|null}}>,
     *     people_outside_organogram: list<array{id: int, name: string, designation: string|null}>,
     *     people_without_login: list<array{id: int, name: string, designation: string|null}>,
     *     attendance_without_office: bool,
     * }
     */
    public function get(int $organizationId): array
    {
        $organization = Organization::query()->findOrFail($organizationId, ['id', 'name']);
        $projects = Project::query()
            ->active()
            ->where('organization_id', $organizationId)
            ->with(['program:id,name', 'teams.members.designation:id,name'])
            ->orderBy('title')
            ->get();
        $people = Member::query()
            ->active()
            ->where('organization_id', $organizationId)
            ->with(['designation:id,name', 'reportsTo.designation:id,name'])
            ->withExists(['teams as on_a_project' => fn (Builder $team): Builder => $team->whereHas('projects', fn (Builder $project): Builder => $project->whereNull('archived_at'))])
            ->orderBy('name')
            ->get();

        return [
            'organization' => ['id' => (int) $organization->id, 'name' => $organization->name],
            'quiet_days' => self::QUIET_DAYS,
            ...$this->projectChecks($projects),
            'programs_without_manager' => $this->programsWithoutManager($organizationId),
            ...$this->peopleChecks($people),
            'attendance_without_office' => $this->attendanceWithoutOffice($organizationId),
        ];
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array{
     *     projects_without_manager: list<array{slug: string, label: string, manager: array{id: int, name: string, designation: string|null}|null}>,
     *     projects_without_people: list<array{slug: string, label: string}>,
     *     unassigned_tasks: list<array{slug: string, label: string, count: int}>,
     *     tasks_with_inactive_people: list<array{slug: string, label: string, count: int}>,
     *     quiet_projects: list<array{slug: string, label: string, last_activity_at: string}>,
     * }
     */
    private function projectChecks(Collection $projects): array
    {
        $ids = $projects->modelKeys();
        $openTasks = fn (): Builder => Task::query()->whereIn('project_id', $ids)->notClosed();
        $countByProject = fn (Builder $tasks): array => $tasks->selectRaw('project_id, count(*) as aggregate')->groupBy('project_id')->pluck('aggregate', 'project_id')->map(fn ($n): int => (int) $n)->all();

        $unassigned = $countByProject($openTasks()->whereDoesntHave('assignments'));
        $leftBehind = $countByProject($openTasks()
            ->whereHas('assignments')
            ->whereDoesntHave('assignments.member', fn (Builder $member): Builder => $member->where('is_active', true)));
        $lastActivity = ProjectActivity::query()
            ->whereIn('project_id', $ids)
            ->selectRaw('project_id, max(created_at) as last_at')
            ->groupBy('project_id')
            ->pluck('last_at', 'project_id');
        $quietSince = CarbonImmutable::now()->subDays(self::QUIET_DAYS);

        $withoutManager = $withoutPeople = $unassignedRows = $leftBehindRows = $quiet = [];

        foreach ($projects as $project) {
            $ref = ['slug' => $project->slug, 'label' => $project->label()];
            $people = $project->team()->members ?? new Collection;
            $manager = $people->first(fn (Member $member): bool => $member->getRelation('pivot')->getAttribute('role') === 'leader');

            if ($manager === null || ! $manager->is_active) {
                $withoutManager[] = [...$ref, 'manager' => $manager === null ? null : PersonRef::of($manager)];
            }

            if (! $people->contains(fn (Member $member): bool => (bool) $member->is_active)) {
                $withoutPeople[] = $ref;
            }

            if (($unassigned[$project->id] ?? 0) > 0) {
                $unassignedRows[] = [...$ref, 'count' => $unassigned[$project->id]];
            }

            if (($leftBehind[$project->id] ?? 0) > 0) {
                $leftBehindRows[] = [...$ref, 'count' => $leftBehind[$project->id]];
            }

            $last = isset($lastActivity[$project->id])
                ? CarbonImmutable::parse((string) $lastActivity[$project->id])
                : $project->created_at;

            if ($last !== null && $last->lessThan($quietSince)) {
                $quiet[] = [...$ref, 'last_activity_at' => $last->toIso8601String()];
            }
        }

        return [
            'projects_without_manager' => $withoutManager,
            'projects_without_people' => $withoutPeople,
            'unassigned_tasks' => $unassignedRows,
            'tasks_with_inactive_people' => $leftBehindRows,
            'quiet_projects' => $quiet,
        ];
    }

    /**
     * @return list<array{slug: string, name: string, manager: array{id: int, name: string, designation: string|null}|null}>
     */
    private function programsWithoutManager(int $organizationId): array
    {
        return array_values(Program::query()
            ->where('organization_id', $organizationId)
            ->with('manager.designation:id,name')
            ->orderBy('name')
            ->get()
            ->reject(fn (Program $program): bool => (bool) $program->manager?->is_active)
            ->map(fn (Program $program): array => [
                'slug' => $program->slug,
                'name' => $program->name,
                'manager' => $program->manager === null ? null : PersonRef::of($program->manager),
            ])
            ->all());
    }

    /**
     * @param  Collection<int, Member>  $people
     * @return array{
     *     people_off_projects: list<array{id: int, name: string, designation: string|null}>,
     *     people_without_designation: list<array{id: int, name: string, designation: string|null}>,
     *     people_reporting_to_inactive: list<array{id: int, name: string, designation: string|null, reports_to: array{id: int, name: string, designation: string|null}}>,
     *     people_outside_organogram: list<array{id: int, name: string, designation: string|null}>,
     *     people_without_login: list<array{id: int, name: string, designation: string|null}>,
     * }
     */
    private function peopleChecks(Collection $people): array
    {
        $withReports = array_flip($people->pluck('reports_to_id')->filter()->map(fn ($id): int => (int) $id)->all());
        $refs = fn (callable $keep): array => array_values($people->filter($keep)->map(PersonRef::of(...))->all());

        return [
            'people_off_projects' => $refs(fn (Member $member): bool => ! $member->getAttribute('on_a_project')),
            'people_without_designation' => $refs(fn (Member $member): bool => $member->designation_id === null),
            'people_reporting_to_inactive' => array_values($people
                ->filter(fn (Member $member): bool => $member->reportsTo !== null && ! $member->reportsTo->is_active)
                ->map(fn (Member $member): array => [...PersonRef::of($member), 'reports_to' => PersonRef::of($member->reportsTo)])
                ->all()),
            'people_outside_organogram' => $refs(fn (Member $member): bool => $member->reports_to_id === null && ! isset($withReports[$member->id])),
            'people_without_login' => $refs(fn (Member $member): bool => $member->user_id === null),
        ];
    }

    private function attendanceWithoutOffice(int $organizationId): bool
    {
        return AttendanceSetting::query()->enabled()->where('organization_id', $organizationId)->exists()
            && ! AttendanceNetwork::query()->where('organization_id', $organizationId)->exists()
            && ! AttendancePlace::query()->where('organization_id', $organizationId)->exists();
    }
}
