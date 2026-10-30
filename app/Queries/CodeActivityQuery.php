<?php

namespace App\Queries;

use App\Models\GitCommit;
use App\Models\GitRepository;
use App\Models\Member;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The code activity report: per person, their commits, lines and pull
 * requests in a date range. It applies WorkspaceAccess::codeReportScope
 * (whole repositories of what the viewer runs, plus their own commits
 * wherever they are) and decides nothing about roles itself.
 *
 * Counts only commits that are not merges and are still reachable (a
 * rebased branch's old commits would count twice), by `committed_at`, which
 * is stored in the app's timezone, so a day is a local day.
 *
 * @phpstan-type Scope array{all: bool, organization_ids: list<int>, program_ids: list<int>, project_ids: list<int>, user_id: ?int}
 * @phpstan-type Filters array{from: CarbonImmutable, to: CarbonImmutable, project: ?Project, program: ?Program, members: list<int>}
 */
class CodeActivityQuery
{
    public const UNMATCHED = 'Not matched to anyone';

    private const COUNTS = ['code_added', 'code_removed', 'comment_added', 'comment_removed', 'blank_added', 'blank_removed', 'docs_added', 'docs_removed'];

    /**
     * @param  Filters  $filters
     * @return array{range: array{from: string, to: string}, people: list<array<string, mixed>>, series: list<array{user_id: int, days: list<array{date: string, code_changed: int, commits: int}>}>, uncounted: int, options: array{projects: list<array{slug: string, title: string, label: string}>, programs: list<array{slug: string, name: string}>}}
     */
    public function get(?Authenticatable $user, array $filters): array
    {
        $scope = WorkspaceAccess::codeReportScope($user);

        return [
            'range' => ['from' => $filters['from']->toDateString(), 'to' => $filters['to']->toDateString()],
            'people' => $this->people($scope, $filters),
            'series' => $this->series($scope, $filters),
            'uncounted' => $this->commits($scope, $filters)->whereNull('git_commits.stats_computed_at')->count(),
            'options' => $this->options($scope),
        ];
    }

    /**
     * One person's latest counted commits, newest first, in the same scope:
     * nothing at all for someone the viewer may not see, whether or not
     * they exist.
     *
     * @param  Filters  $filters
     * @return list<array{sha: string, message: string, repository: string, url: string, committed_at: string, code_added: int, code_removed: int, comment_added: int, comment_removed: int, stats_status: ?string}>
     */
    public function commitsOf(?Authenticatable $user, int $userId, array $filters, int $limit = 50): array
    {
        return array_values($this->commits(WorkspaceAccess::codeReportScope($user), $filters)
            ->where('git_commits.user_id', $userId)
            ->with('repository:id,full_name')
            ->orderByDesc('git_commits.committed_at')
            ->orderByDesc('git_commits.id')
            ->limit($limit)
            ->get()
            ->map(fn (GitCommit $commit): array => [
                'sha' => $commit->sha,
                'message' => trim(Str::before($commit->message, "\n")),
                'repository' => $commit->repository->full_name,
                'url' => $commit->url,
                'committed_at' => $commit->committed_at->toIso8601String(),
                'code_added' => $commit->code_added,
                'code_removed' => $commit->code_removed,
                'comment_added' => $commit->comment_added,
                'comment_removed' => $commit->comment_removed,
                'stats_status' => $commit->stats_status,
            ])
            ->all());
    }

    /**
     * Whether a project filter stays inside the viewer's whole repositories.
     */
    public function coversProject(?Authenticatable $user, Project $project): bool
    {
        return $this->scopedProjects(Project::query()->whereKey($project->getKey()), WorkspaceAccess::codeReportScope($user))->exists();
    }

    /**
     * Whether a program filter stays inside the viewer's whole repositories.
     */
    public function coversProgram(?Authenticatable $user, Program $program): bool
    {
        return $this->scopedPrograms(Program::query()->whereKey($program->getKey()), WorkspaceAccess::codeReportScope($user))->exists();
    }

    /**
     * One row per person, most code changed first; the commits no user is
     * matched to are one row, only for a viewer who sees whole repositories.
     *
     * @param  Scope  $scope
     * @param  Filters  $filters
     * @return list<array<string, mixed>>
     */
    private function people(array $scope, array $filters): array
    {
        $commits = $this->commits($scope, $filters)->toBase()
            ->select('git_commits.user_id')
            ->selectRaw('count(*) as commits')
            ->selectRaw('count(distinct date(git_commits.committed_at)) as active_days')
            ->selectRaw('count(distinct git_commits.git_repository_id) as repositories')
            ->selectRaw('coalesce(sum(git_commits.files_changed), 0) as files_changed');

        foreach (self::COUNTS as $column) {
            $commits->selectRaw("coalesce(sum(git_commits.{$column}), 0) as {$column}");
        }

        $tasks = $this->commits($scope, $filters)->toBase()
            ->join('task_git_links', fn (JoinClause $join) => $join
                ->on('task_git_links.linkable_id', '=', 'git_commits.id')
                ->where('task_git_links.linkable_type', '=', (new GitCommit)->getMorphClass()))
            ->join('tasks', 'tasks.id', '=', 'task_git_links.task_id')
            ->whereNull('tasks.deleted_at')
            ->select('git_commits.user_id')
            ->selectRaw('count(distinct task_git_links.task_id) as tasks')
            ->groupBy('git_commits.user_id')
            ->pluck('tasks', 'user_id');

        /** @var array<int|string, array<string, int>> $rows keyed by user id, '' for no one */
        $rows = [];

        foreach ($commits->groupBy('git_commits.user_id')->get() as $row) {
            $key = $row->user_id ?? '';
            $rows[$key] = ['tasks' => (int) ($tasks[$key] ?? 0)];

            foreach (['commits', 'active_days', 'repositories', 'files_changed', ...self::COUNTS] as $column) {
                $rows[$key][$column] = (int) $row->{$column};
            }
        }

        foreach ($this->pullRequests($scope, $filters) as $row) {
            $key = $row->user_id ?? '';
            $rows[$key] = ['pull_requests_opened' => (int) $row->opened, 'pull_requests_merged' => (int) $row->merged] + ($rows[$key] ?? []);
        }

        if (! $this->seesWholeRepositories($scope)) {
            unset($rows['']);
        }

        $names = $this->names(array_values(array_filter(array_keys($rows), fn ($key): bool => $key !== '')));
        $name = fn (int|string $key): string => $key === '' ? self::UNMATCHED : ($names[(int) $key]['name'] ?? '');
        $changed = array_map(fn (array $counts): int => ($counts['code_added'] ?? 0) + ($counts['code_removed'] ?? 0), $rows);

        uksort($rows, fn (int|string $a, int|string $b): int => $changed[$b] <=> $changed[$a] ?: strcasecmp($name($a), $name($b)));

        $people = [];

        foreach ($rows as $key => $counts) {
            $people[] = [
                'user_id' => $key === '' ? null : (int) $key,
                'name' => $name($key),
                'title' => $key === '' ? null : ($names[(int) $key]['title'] ?? null),
                ...array_fill_keys(['commits', 'active_days', 'repositories', 'files_changed', ...self::COUNTS, 'pull_requests_opened', 'pull_requests_merged', 'tasks'], 0),
                ...$counts,
            ];
        }

        return $people;
    }

    /**
     * Pull requests opened or merged in the range, per author (matched to a
     * user by forge username, in any case; null when no one): those of whole
     * repositories, plus the viewer's own wherever they are.
     *
     * @param  Scope  $scope
     * @param  Filters  $filters
     * @return Collection<int, \stdClass> each `{user_id, opened, merged}`
     */
    private function pullRequests(array $scope, array $filters): Collection
    {
        $between = $this->between($filters);

        $query = DB::table('git_pull_requests')
            ->leftJoin('users', DB::raw('lower(users.git_username)'), '=', DB::raw('lower(git_pull_requests.author_username)'))
            ->where(fn (QueryBuilder $q) => $q
                ->whereBetween('git_pull_requests.opened_at', $between)
                ->orWhereBetween('git_pull_requests.merged_at', $between))
            ->where(function (QueryBuilder $q) use ($scope): void {
                $q->whereIn('git_pull_requests.git_repository_id', $this->wholeRepositories($scope));

                if ($scope['user_id'] !== null) {
                    $q->orWhere('users.id', $scope['user_id']);
                }
            })
            ->select('users.id as user_id')
            ->selectRaw('sum(case when git_pull_requests.opened_at between ? and ? then 1 else 0 end) as opened', $between)
            ->selectRaw('sum(case when git_pull_requests.merged_at between ? and ? then 1 else 0 end) as merged', $between)
            ->groupBy('users.id');

        return $this->filtered($query, 'git_pull_requests.git_repository_id', $filters)->get();
    }

    /**
     * Code lines changed and commits per day, for each person compared,
     * every day of the range (zero days included).
     *
     * @param  Scope  $scope
     * @param  Filters  $filters
     * @return list<array{user_id: int, days: list<array{date: string, code_changed: int, commits: int}>}>
     */
    private function series(array $scope, array $filters): array
    {
        if ($filters['members'] === []) {
            return [];
        }

        $totals = [];

        $rows = $this->commits($scope, $filters)->toBase()
            ->whereIn('git_commits.user_id', $filters['members'])
            ->select('git_commits.user_id')
            ->selectRaw('date(git_commits.committed_at) as day')
            ->selectRaw('coalesce(sum(git_commits.code_added + git_commits.code_removed), 0) as code_changed')
            ->selectRaw('count(*) as commits')
            ->groupByRaw('git_commits.user_id, date(git_commits.committed_at)')
            ->get();

        foreach ($rows as $row) {
            $totals[(int) $row->user_id][(string) $row->day] = ['code_changed' => (int) $row->code_changed, 'commits' => (int) $row->commits];
        }

        $days = array_values(array_map(
            fn (CarbonInterface $day): string => $day->toDateString(),
            CarbonPeriod::create($filters['from']->startOfDay(), $filters['to']->startOfDay())->toArray(),
        ));

        return array_map(fn (int $userId): array => [
            'user_id' => $userId,
            'days' => array_map(fn (string $date): array => ['date' => $date] + ($totals[$userId][$date] ?? ['code_changed' => 0, 'commits' => 0]), $days),
        ], $filters['members']);
    }

    /**
     * The projects and programs the viewer may filter by: in their whole
     * repositories, with a linked repository. A project's `label` names its
     * program too (Project::label()), since every program has a General.
     *
     * @param  Scope  $scope
     * @return array{projects: list<array{slug: string, title: string, label: string}>, programs: list<array{slug: string, name: string}>}
     */
    private function options(array $scope): array
    {
        $projects = $this->scopedProjects(Project::query()->whereHas('repositories'), $scope)
            ->with('program:id,name')
            ->orderBy('title')
            ->orderBy('id')
            ->get(['id', 'slug', 'title', 'program_id'])
            ->map(fn (Project $project): array => ['slug' => $project->slug, 'title' => $project->title, 'label' => $project->label()]);

        $programs = $this->scopedPrograms(Program::query(), $scope)
            ->whereHas('projects', fn (Builder $projects) => $projects
                ->whereColumn('projects.organization_id', 'programs.organization_id')
                ->whereHas('repositories'))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['slug', 'name'])
            ->map(fn (Program $program): array => ['slug' => $program->slug, 'name' => $program->name]);

        return ['projects' => array_values($projects->all()), 'programs' => array_values($programs->all())];
    }

    /**
     * The counted commits: not merges, still reachable, in the range and
     * the filters, and in scope.
     *
     * @param  Scope  $scope
     * @param  Filters  $filters
     * @return Builder<GitCommit>
     */
    private function commits(array $scope, array $filters): Builder
    {
        $query = GitCommit::query()
            ->where('git_commits.is_merge', false)
            ->whereNull('git_commits.unreachable_at')
            ->whereBetween('git_commits.committed_at', $this->between($filters))
            ->where(function (Builder $q) use ($scope): void {
                $q->whereIn('git_commits.git_repository_id', $this->wholeRepositories($scope));

                if ($scope['user_id'] !== null) {
                    $q->orWhere('git_commits.user_id', $scope['user_id']);
                }
            });

        $this->filtered($query->getQuery(), 'git_commits.git_repository_id', $filters);

        return $query;
    }

    /**
     * Narrows to the repositories of the project or program filter.
     *
     * @param  Filters  $filters
     */
    private function filtered(QueryBuilder $query, string $repositoryColumn, array $filters): QueryBuilder
    {
        if ($filters['project'] !== null) {
            $query->whereIn($repositoryColumn, GitRepository::query()->select('id')->where('project_id', $filters['project']->getKey()));
        }

        if ($filters['program'] !== null) {
            $query->whereIn($repositoryColumn, GitRepository::query()
                ->select('git_repositories.id')
                ->join('projects', 'projects.id', '=', 'git_repositories.project_id')
                ->where('projects.program_id', $filters['program']->getKey())
                ->where('projects.organization_id', $filters['program']->organization_id));
        }

        return $query;
    }

    /**
     * The ids of the repositories the viewer sees whole (none for someone
     * who only sees their own commits).
     *
     * @param  Scope  $scope
     * @return Builder<GitRepository>
     */
    private function wholeRepositories(array $scope): Builder
    {
        return GitRepository::query()
            ->select('git_repositories.id')
            ->whereIn('git_repositories.project_id', $this->scopedProjects(Project::query()->select('projects.id'), $scope));
    }

    /**
     * Projects whose repositories the viewer sees whole. A program's
     * project counts only in the program's own organization (as
     * WorkspaceAccess::managesProgramOf).
     *
     * @param  Builder<Project>  $projects
     * @param  Scope  $scope
     * @return Builder<Project>
     */
    private function scopedProjects(Builder $projects, array $scope): Builder
    {
        if ($scope['all']) {
            return $projects;
        }

        return $projects->where(fn (Builder $q) => $q
            ->whereIn('projects.organization_id', $scope['organization_ids'])
            ->orWhereIn('projects.id', $scope['project_ids'])
            ->orWhereIn('projects.program_id', Program::query()
                ->select('programs.id')
                ->whereIn('programs.id', $scope['program_ids'])
                ->whereColumn('programs.organization_id', 'projects.organization_id')));
    }

    /**
     * @param  Builder<Program>  $programs
     * @param  Scope  $scope
     * @return Builder<Program>
     */
    private function scopedPrograms(Builder $programs, array $scope): Builder
    {
        if ($scope['all']) {
            return $programs;
        }

        return $programs->where(fn (Builder $q) => $q
            ->whereIn('programs.organization_id', $scope['organization_ids'])
            ->orWhereIn('programs.id', $scope['program_ids']));
    }

    /**
     * @param  Scope  $scope
     */
    private function seesWholeRepositories(array $scope): bool
    {
        return $scope['all'] || $scope['organization_ids'] !== [] || $scope['program_ids'] !== [] || $scope['project_ids'] !== [];
    }

    /**
     * Each user's name and title: their member's when they have one (the
     * people directory's), else the login's name.
     *
     * @param  list<int|string>  $userIds
     * @return array<int, array{name: string, title: ?string}>
     */
    private function names(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $names = [];

        foreach (User::query()->whereIn('id', $userIds)->get(['id', 'name']) as $user) {
            $names[$user->id] = ['name' => $user->name, 'title' => null];
        }

        foreach (Member::query()->whereIn('user_id', $userIds)->get(['user_id', 'name', 'title']) as $member) {
            $names[(int) $member->user_id] = ['name' => $member->name, 'title' => $member->title];
        }

        return $names;
    }

    /**
     * The range as whole local days: from 00:00:00 to 23:59:59.
     *
     * @param  Filters  $filters
     * @return array{0: string, 1: string}
     */
    private function between(array $filters): array
    {
        return [
            $filters['from']->startOfDay()->format('Y-m-d H:i:s'),
            $filters['to']->endOfDay()->format('Y-m-d H:i:s'),
        ];
    }
}
