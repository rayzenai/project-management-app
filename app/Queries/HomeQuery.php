<?php

namespace App\Queries;

use App\Http\Resources\TaskResource;
use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Assembles the Home payload: the headline metrics, the status split, the
 * per-project rollup, and the open work bucketed by when it is due.
 *
 * Everything on the payload obeys the same scope, so the number on a card and
 * the rows underneath it can never disagree.
 *
 * Two scopes. `mine` is the work assigned to the acting member; `all` is every
 * task in a project they can see. The default is `mine` when they have open
 * assignments and `all` when they do not, so a super-admin who is assigned
 * nothing still lands on a populated screen rather than an empty one.
 */
class HomeQuery
{
    /** Buckets in the order the page renders them. */
    private const BUCKET_LABELS = [
        'overdue' => 'Overdue',
        'today' => 'Today',
        'week' => 'This week',
        'later' => 'Later',
        'unscheduled' => 'No date',
    ];

    /**
     * @return array{
     *     scope: string,
     *     buckets: list<array{key: string, label: string, tasks: list<array<string, mixed>>}>,
     *     recently_done: list<array<string, mixed>>,
     *     stats: array<string, int>,
     *     status_breakdown: list<array{value: string, label: string, color: string, count: int}>,
     *     projects: list<array<string, mixed>>
     * }
     */
    public function get(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        $memberId = (int) Member::forUser($user)->getKey();
        $today = CarbonImmutable::today();
        $weekEnd = $today->addDays(7);
        $stalledBefore = CarbonImmutable::now()->subDays(14);

        $scope = $this->resolveScope($request, $user, $memberId);

        $open = $this->inScope($user, $scope, $memberId)
            ->incomplete()
            ->with(['project:id,slug,title', 'assignments.member'])
            // Soonest first, then by how loudly the task is asking to be done.
            ->orderByRaw('deadline_at ASC NULLS LAST')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderByItemNumber()
            ->get();

        $recentlyDone = $this->inScope($user, $scope, $memberId)
            ->complete()
            ->with(['project:id,slug,title', 'assignments.member'])
            ->where('completed_at', '>=', $today->subDays(7))
            ->orderByDesc('completed_at')
            ->limit(20)
            ->get();

        $buckets = [];
        $counts = [];

        foreach (self::BUCKET_LABELS as $key => $label) {
            $tasks = $open
                ->filter(fn (Task $task): bool => $this->bucketFor($task, $today, $weekEnd) === $key)
                ->values();

            $counts[$key] = $tasks->count();

            $buckets[] = [
                'key' => $key,
                'label' => $label,
                'tasks' => array_values(TaskResource::collection($tasks)->resolve()),
            ];
        }

        $all = $this->inScope($user, $scope, $memberId)
            ->get(['id', 'project_id', 'status', 'deadline_at', 'status_updated_at']);

        $total = $all->count();
        $complete = $all->filter(fn (Task $task): bool => $task->isComplete())->count();

        return [
            'scope' => $scope,
            'buckets' => $buckets,
            'recently_done' => array_values(TaskResource::collection($recentlyDone)->resolve()),
            'stats' => [
                'open' => $open->count(),
                'overdue' => $counts['overdue'],
                'today' => $counts['today'],
                'week' => $counts['week'],
                'unscheduled' => $counts['unscheduled'],
                'stalled' => $open->filter(
                    fn (Task $task): bool => $task->status_updated_at !== null
                        && $task->status_updated_at->lt($stalledBefore),
                )->count(),
                'percent_complete' => $total === 0 ? 0 : (int) round($complete / $total * 100),
                'done_this_week' => $recentlyDone->count(),
                'total' => $total,
                'complete' => $complete,
            ],
            'status_breakdown' => $this->statusBreakdown($all),
            'projects' => $this->projectRollup($user, $all, $scope, $today, $weekEnd, $stalledBefore),
        ];
    }

    /**
     * Part-to-whole split across the configured workflow. Every status is kept
     * even at zero so the legend does not reshuffle between loads.
     *
     * @param  Collection<int, Task>  $tasks
     * @return list<array{value: string, label: string, color: string, count: int}>
     */
    private function statusBreakdown(Collection $tasks): array
    {
        $counts = $tasks->countBy('status');

        $rows = collect((array) config('project-management.statuses'))
            ->map(fn (array $meta, string $value): array => [
                'value' => $value,
                'label' => (string) ($meta['label'] ?? $value),
                'color' => (string) ($meta['color'] ?? '#9CA3AF'),
                'count' => (int) ($counts[$value] ?? 0),
            ]);

        return array_values($rows->all());
    }

    /**
     * One row per live project the user can see, counted over the same task set
     * as the cards above it. Under the `mine` scope, projects the caller has no
     * assignment on drop out entirely.
     *
     * @param  Collection<int, Task>  $tasks
     * @return list<array<string, mixed>>
     */
    private function projectRollup(
        User $user,
        Collection $tasks,
        string $scope,
        CarbonImmutable $today,
        CarbonImmutable $weekEnd,
        CarbonImmutable $stalledBefore,
    ): array {
        $byProject = $tasks->groupBy('project_id');

        $rows = Project::query()
            ->visibleTo($user)
            ->active()
            ->orderBy('title')
            ->get(['id', 'slug', 'title', 'title_np'])
            ->map(function (Project $project) use ($byProject, $today, $weekEnd, $stalledBefore): array {
                /** @var Collection<int, Task> $items */
                $items = $byProject->get($project->id) ?? new Collection;

                $open = $items->reject(fn (Task $task): bool => $task->isComplete());
                $complete = $items->count() - $open->count();

                return [
                    'slug' => $project->slug,
                    'title' => $project->title,
                    'title_np' => $project->title_np,
                    'tasks_count' => $items->count(),
                    'complete' => $complete,
                    'percent_complete' => $items->count() === 0
                        ? 0
                        : (int) round($complete / $items->count() * 100),
                    'overdue' => $open->filter(
                        fn (Task $task): bool => $task->deadline_at !== null
                            && $task->deadline_at->startOfDay()->lt($today),
                    )->count(),
                    'due_this_week' => $open->filter(
                        fn (Task $task): bool => $task->deadline_at !== null
                            && $task->deadline_at->startOfDay()->gte($today)
                            && $task->deadline_at->startOfDay()->lte($weekEnd),
                    )->count(),
                    'stalled' => $open->filter(
                        fn (Task $task): bool => $task->status_updated_at !== null
                            && $task->status_updated_at->lt($stalledBefore),
                    )->count(),
                ];
            })
            // Under `mine` a project with nothing assigned to the caller would
            // read as an empty project rather than one they are simply not on.
            ->reject(fn (array $row): bool => $scope === 'mine' && $row['tasks_count'] === 0);

        return array_values($rows->all());
    }

    /**
     * Tasks in a live project the user can see, narrowed to their own
     * assignments when the scope says so.
     *
     * @return Builder<Task>
     */
    private function inScope(User $user, string $scope, int $memberId): Builder
    {
        return Task::query()
            ->forActiveProjects()
            ->whereIn('project_id', Project::query()->visibleTo($user)->select('id'))
            ->when(
                $scope === 'mine',
                fn (Builder $query): Builder => $query->whereHas(
                    'assignments',
                    fn (Builder $q) => $q->where('member_id', $memberId),
                ),
            );
    }

    /**
     * An explicit ?scope= wins; otherwise fall back to whichever scope has
     * something in it.
     */
    private function resolveScope(Request $request, User $user, int $memberId): string
    {
        $requested = (string) $request->query('scope', '');

        if (in_array($requested, ['mine', 'all'], true)) {
            return $requested;
        }

        return $this->inScope($user, 'mine', $memberId)->incomplete()->exists() ? 'mine' : 'all';
    }

    private function bucketFor(Task $task, CarbonImmutable $today, CarbonImmutable $weekEnd): string
    {
        if ($task->deadline_at === null) {
            return 'unscheduled';
        }

        $deadline = $task->deadline_at->startOfDay();

        return match (true) {
            $deadline->lt($today) => 'overdue',
            $deadline->equalTo($today) => 'today',
            $deadline->lte($weekEnd) => 'week',
            default => 'later',
        };
    }
}
