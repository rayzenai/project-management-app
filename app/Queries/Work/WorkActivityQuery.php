<?php

namespace App\Queries\Work;

use App\Models\GitCommit;
use App\Models\GitPullRequest;
use App\Models\GitRepository;
use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectAssignment;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What people did over a range of whole days, per member: the one read
 * today's work, a person's work and a team's work share.
 *
 * - Tasks closed and started: status changes in the activity log made by
 *   the member's login (whoever changed the status is credited), into a
 *   closed status (Task::closedStatuses(), the one entered is shown) or a
 *   started one (Task::startedStatuses()). A task counts once per list, as
 *   its latest change in the range. Trashed tasks count nowhere.
 * - Commits matched to the member's login (`git_commits.user_id`), not
 *   merges, still reachable, by `committed_at`.
 * - Pull requests merged in the range whose author is the member's git
 *   username, in any case.
 * - Overdue (only when asked, else null): tasks assigned to the member
 *   that are overdue now (Task::scopeOverdue()), in projects not archived.
 *
 * Only tasks of projects the viewer may view (Project::scopeVisibleTo())
 * and repositories whose code they may see (Project::scopeCodeVisibleTo())
 * are named; the rest are counted (`hidden_*`), never named. Days are local
 * days: timestamps are stored in the app's timezone.
 *
 * A fixed number of queries for any number of members.
 *
 * @phpstan-type TaskRef array{id: int, code: string, title: string, project: string, project_slug: string, slug: string}
 * @phpstan-type WorkTask array{id: int, code: string, title: string, project: string, project_slug: string, slug: string, status: string, at: string}
 * @phpstan-type OverdueTask array{id: int, code: string, title: string, project: string, project_slug: string, slug: string, status: string, deadline_at: string}
 * @phpstan-type Code array{count: int, code_added: int, code_removed: int, comment_added: int, comment_removed: int, repositories: list<array{name: string, count: int}>, hidden_commits: int}
 * @phpstan-type PullRequest array{title: string, number: int, repository: string, url: string, merged_at: string}
 * @phpstan-type Activity array{tasks_closed: list<WorkTask>, hidden_closed: int, tasks_started: list<WorkTask>, hidden_started: int, commits: Code, pull_requests_merged: list<PullRequest>, hidden_pull_requests: int, overdue: list<OverdueTask>|null, hidden_overdue: int|null}
 */
final class WorkActivityQuery
{
    /**
     * @param  iterable<Member>  $members
     * @return array<int, Activity> keyed by member id, one entry for each member
     */
    public function forMembers(Authenticatable $viewer, iterable $members, CarbonImmutable $from, CarbonImmutable $to, bool $withOverdue = false): array
    {
        $members = collect($members);
        $between = [$from->startOfDay()->format('Y-m-d H:i:s'), $to->endOfDay()->format('Y-m-d H:i:s')];

        /** @var array<int, int> $memberOfUser user id => member id */
        $memberOfUser = [];

        foreach ($members as $member) {
            if ($member->user_id !== null) {
                $memberOfUser[(int) $member->user_id] = (int) $member->getKey();
            }
        }

        $userIds = array_keys($memberOfUser);
        $memberIds = array_values($members->map(fn (Member $member): int => (int) $member->getKey())->all());

        $changes = $this->statusChanges($userIds, $between);
        $overdue = $withOverdue ? $this->overdueAssignments($memberIds) : collect();
        $tasks = $this->tasks($changes->pluck('task_id')->merge($overdue->pluck('task_id')));
        $visibleProjects = $this->visibleProjectIds($viewer, $tasks);

        $commits = $this->commits($userIds, $between);
        $pullRequests = $this->pullRequests($userIds, $between);
        $repositories = $this->codeVisibleRepositories($viewer, $commits->pluck('git_repository_id')->merge($pullRequests->pluck('git_repository_id')));

        $taskLists = $this->taskLists($changes, $memberOfUser, $tasks, $visibleProjects);
        $code = $this->code($commits, $memberOfUser, $repositories);
        $merged = $this->mergedLists($pullRequests, $memberOfUser, $repositories);
        $overdueLists = $this->overdueLists($overdue, $tasks, $visibleProjects);

        $result = [];

        foreach ($memberIds as $memberId) {
            $result[$memberId] = [
                'tasks_closed' => $taskLists[$memberId]['closed'] ?? [],
                'hidden_closed' => $taskLists[$memberId]['hidden_closed'] ?? 0,
                'tasks_started' => $taskLists[$memberId]['started'] ?? [],
                'hidden_started' => $taskLists[$memberId]['hidden_started'] ?? 0,
                'commits' => $code[$memberId] ?? $this->noCode(),
                'pull_requests_merged' => $merged[$memberId]['named'] ?? [],
                'hidden_pull_requests' => $merged[$memberId]['hidden'] ?? 0,
                'overdue' => $withOverdue ? ($overdueLists[$memberId]['named'] ?? []) : null,
                'hidden_overdue' => $withOverdue ? ($overdueLists[$memberId]['hidden'] ?? 0) : null,
            ];
        }

        return $result;
    }

    /**
     * The status changes into a closed or started status, oldest first.
     *
     * @param  list<int>  $userIds
     * @param  array{0: string, 1: string}  $between
     * @return Collection<int, ProjectActivity>
     */
    private function statusChanges(array $userIds, array $between): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return ProjectActivity::query()
            ->where('action', ProjectActivity::ACTION_STATUS_CHANGED)
            ->whereIn('user_id', $userIds)
            ->whereNotNull('task_id')
            ->whereIn('changes->to', [...Task::closedStatuses(), ...Task::startedStatuses()])
            ->whereBetween('created_at', $between)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'user_id', 'task_id', 'changes', 'created_at'])
            ->toBase();
    }

    /**
     * Who is assigned to which overdue task.
     *
     * @param  list<int>  $memberIds
     * @return Collection<int, ProjectAssignment>
     */
    private function overdueAssignments(array $memberIds): Collection
    {
        if ($memberIds === []) {
            return collect();
        }

        return ProjectAssignment::query()
            ->whereIn('member_id', $memberIds)
            ->whereIn('task_id', Task::query()->overdue()->forActiveProjects()->select('tasks.id'))
            ->get(['id', 'member_id', 'task_id'])
            ->toBase();
    }

    /**
     * The tasks named, with their project and its program (for the label).
     *
     * @param  Collection<int, mixed>  $ids
     * @return Collection<int, Task> keyed by id
     */
    private function tasks(Collection $ids): Collection
    {
        $ids = $ids->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Task::query()
            ->whereIn('id', $ids->all())
            ->with(['project:id,key,title,slug,program_id', 'project.program:id,name'])
            ->get(['id', 'project_id', 'number', 'slug', 'title', 'status', 'deadline_at'])
            ->keyBy('id')
            ->toBase();
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return array<int, bool> the ids of the tasks' projects the viewer may view, as keys
     */
    private function visibleProjectIds(Authenticatable $viewer, Collection $tasks): array
    {
        $projectIds = $tasks->pluck('project_id')->unique()->values()->all();

        if ($projectIds === []) {
            return [];
        }

        return array_fill_keys(
            array_map(intval(...), Project::query()->visibleTo($viewer)->whereIn('projects.id', $projectIds)->pluck('projects.id')->all()),
            true,
        );
    }

    /**
     * Counted commits per login and repository.
     *
     * @param  list<int>  $userIds
     * @param  array{0: string, 1: string}  $between
     * @return Collection<int, \stdClass> each `{user_id, git_repository_id, commits, code_added, code_removed, comment_added, comment_removed}`
     */
    private function commits(array $userIds, array $between): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return GitCommit::query()->toBase()
            ->whereIn('user_id', $userIds)
            ->where('is_merge', false)
            ->whereNull('unreachable_at')
            ->whereBetween('committed_at', $between)
            ->select('user_id', 'git_repository_id')
            ->selectRaw('count(*) as commits')
            ->selectRaw('coalesce(sum(code_added), 0) as code_added')
            ->selectRaw('coalesce(sum(code_removed), 0) as code_removed')
            ->selectRaw('coalesce(sum(comment_added), 0) as comment_added')
            ->selectRaw('coalesce(sum(comment_removed), 0) as comment_removed')
            ->groupBy('user_id', 'git_repository_id')
            ->get();
    }

    /**
     * Pull requests merged in the range, with the login whose git username
     * authored them, newest first.
     *
     * @param  list<int>  $userIds
     * @param  array{0: string, 1: string}  $between
     * @return Collection<int, \stdClass> each `{user_id, git_repository_id, number, title, url, merged_at}`
     */
    private function pullRequests(array $userIds, array $between): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return GitPullRequest::query()->toBase()
            ->join('users', DB::raw('lower(users.git_username)'), '=', DB::raw('lower(git_pull_requests.author_username)'))
            ->whereIn('users.id', $userIds)
            ->whereNotNull('git_pull_requests.merged_at')
            ->whereBetween('git_pull_requests.merged_at', $between)
            ->orderByDesc('git_pull_requests.merged_at')
            ->orderByDesc('git_pull_requests.id')
            ->get([
                'users.id as user_id',
                'git_pull_requests.git_repository_id',
                'git_pull_requests.number',
                'git_pull_requests.title',
                'git_pull_requests.url',
                'git_pull_requests.merged_at',
            ]);
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @return array<int, string> name by id, for the repositories whose code the viewer may see
     */
    private function codeVisibleRepositories(Authenticatable $viewer, Collection $ids): array
    {
        $ids = $ids->map(fn (mixed $id): int => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $names = [];

        foreach (GitRepository::query()
            ->whereIn('id', $ids->all())
            ->whereIn('project_id', Project::query()->codeVisibleTo($viewer)->select('projects.id'))
            ->get(['id', 'name']) as $repository) {
            $names[(int) $repository->getKey()] = $repository->name;
        }

        return $names;
    }

    /**
     * Each member's closed and started tasks, each task once per list at
     * its latest change, newest first; those the viewer may not see only
     * counted.
     *
     * @param  Collection<int, ProjectActivity>  $changes  oldest first
     * @param  array<int, int>  $memberOfUser
     * @param  Collection<int, Task>  $tasks
     * @param  array<int, bool>  $visibleProjects
     * @return array<int, array{closed: list<WorkTask>, hidden_closed: int, started: list<WorkTask>, hidden_started: int}>
     */
    private function taskLists(Collection $changes, array $memberOfUser, Collection $tasks, array $visibleProjects): array
    {
        $kinds = ['closed' => Task::closedStatuses(), 'started' => Task::startedStatuses()];

        /** @var array<int, array<string, array<int, array{status: string, at: CarbonInterface}>>> $latest member => kind => task => change */
        $latest = [];

        foreach ($changes as $change) {
            $memberId = $memberOfUser[(int) $change->user_id] ?? null;
            $taskId = (int) $change->task_id;
            $status = (string) ($change->changes['to'] ?? '');

            if ($memberId === null || $change->created_at === null || ! $tasks->has($taskId)) {
                continue;
            }

            foreach ($kinds as $kind => $statuses) {
                if (in_array($status, $statuses, true)) {
                    // Re-adding moves the task to the end: the order is by latest change.
                    unset($latest[$memberId][$kind][$taskId]);
                    $latest[$memberId][$kind][$taskId] = ['status' => $status, 'at' => $change->created_at];
                }
            }
        }

        $lists = [];

        foreach ($latest as $memberId => $byKind) {
            [$closed, $hiddenClosed] = $this->named($byKind['closed'] ?? [], $tasks, $visibleProjects);
            [$started, $hiddenStarted] = $this->named($byKind['started'] ?? [], $tasks, $visibleProjects);
            $lists[$memberId] = ['closed' => $closed, 'hidden_closed' => $hiddenClosed, 'started' => $started, 'hidden_started' => $hiddenStarted];
        }

        return $lists;
    }

    /**
     * @param  array<int, array{status: string, at: CarbonInterface}>  $byTask  oldest first
     * @param  Collection<int, Task>  $tasks
     * @param  array<int, bool>  $visibleProjects
     * @return array{0: list<WorkTask>, 1: int} the tasks the viewer may see, newest first, and how many others
     */
    private function named(array $byTask, Collection $tasks, array $visibleProjects): array
    {
        $named = [];
        $hidden = 0;

        foreach (array_reverse($byTask, true) as $taskId => $change) {
            $task = $tasks->get($taskId);

            if ($task === null) {
                continue;
            }

            if (! isset($visibleProjects[$task->project_id])) {
                $hidden++;

                continue;
            }

            $named[] = [...$this->taskRef($task), 'status' => $change['status'], 'at' => $change['at']->toIso8601String()];
        }

        return [$named, $hidden];
    }

    /**
     * Each member's commit counts and lines; repositories by commits, the
     * ones the viewer may not see only counted.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @param  array<int, int>  $memberOfUser
     * @param  array<int, string>  $repositories
     * @return array<int, Code>
     */
    private function code(Collection $rows, array $memberOfUser, array $repositories): array
    {
        $code = [];

        foreach ($rows as $row) {
            $memberId = $memberOfUser[(int) $row->user_id];
            $count = (int) $row->commits;
            $entry = $code[$memberId] ?? $this->noCode();
            $name = $repositories[(int) $row->git_repository_id] ?? null;

            $entry['count'] += $count;
            $entry['code_added'] += (int) $row->code_added;
            $entry['code_removed'] += (int) $row->code_removed;
            $entry['comment_added'] += (int) $row->comment_added;
            $entry['comment_removed'] += (int) $row->comment_removed;

            if ($name === null) {
                $entry['hidden_commits'] += $count;
            } else {
                $entry['repositories'][] = ['name' => $name, 'count' => $count];
            }

            $code[$memberId] = $entry;
        }

        foreach ($code as $memberId => $entry) {
            usort($entry['repositories'], fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcasecmp($a['name'], $b['name']));
            $code[$memberId] = $entry;
        }

        return $code;
    }

    /**
     * @return Code
     */
    private function noCode(): array
    {
        return ['count' => 0, 'code_added' => 0, 'code_removed' => 0, 'comment_added' => 0, 'comment_removed' => 0, 'repositories' => [], 'hidden_commits' => 0];
    }

    /**
     * @param  Collection<int, \stdClass>  $rows  newest first
     * @param  array<int, int>  $memberOfUser
     * @param  array<int, string>  $repositories
     * @return array<int, array{named: list<PullRequest>, hidden: int}>
     */
    private function mergedLists(Collection $rows, array $memberOfUser, array $repositories): array
    {
        $lists = [];

        foreach ($rows as $row) {
            $memberId = $memberOfUser[(int) $row->user_id];
            $lists[$memberId] ??= ['named' => [], 'hidden' => 0];
            $name = $repositories[(int) $row->git_repository_id] ?? null;

            if ($name === null) {
                $lists[$memberId]['hidden']++;

                continue;
            }

            $lists[$memberId]['named'][] = [
                'title' => (string) $row->title,
                'number' => (int) $row->number,
                'repository' => $name,
                'url' => (string) $row->url,
                'merged_at' => CarbonImmutable::parse((string) $row->merged_at)->toIso8601String(),
            ];
        }

        return $lists;
    }

    /**
     * Each member's overdue tasks, soonest deadline first; those the viewer
     * may not see only counted.
     *
     * @param  Collection<int, ProjectAssignment>  $assignments
     * @param  Collection<int, Task>  $tasks
     * @param  array<int, bool>  $visibleProjects
     * @return array<int, array{named: list<OverdueTask>, hidden: int}>
     */
    private function overdueLists(Collection $assignments, Collection $tasks, array $visibleProjects): array
    {
        $byDeadline = $assignments
            ->unique(fn (ProjectAssignment $assignment): string => $assignment->member_id.'-'.$assignment->task_id)
            ->sortBy(fn (ProjectAssignment $assignment): string => ($tasks->get($assignment->task_id)?->deadline_at?->format('Y-m-d H:i:s') ?? '').'-'.str_pad((string) $assignment->task_id, 12, '0', STR_PAD_LEFT));

        $lists = [];

        foreach ($byDeadline as $assignment) {
            $task = $tasks->get($assignment->task_id);
            $memberId = (int) $assignment->member_id;

            if ($task === null) {
                continue;
            }

            $lists[$memberId] ??= ['named' => [], 'hidden' => 0];

            if (! isset($visibleProjects[$task->project_id])) {
                $lists[$memberId]['hidden']++;

                continue;
            }

            $lists[$memberId]['named'][] = [
                ...$this->taskRef($task),
                'status' => $task->status,
                'deadline_at' => $task->deadline_at?->toIso8601String() ?? '',
            ];
        }

        return $lists;
    }

    /**
     * @return TaskRef
     */
    private function taskRef(Task $task): array
    {
        /** @var Project $project */
        $project = $task->project;

        return [
            'id' => (int) $task->getKey(),
            'code' => "{$project->code}-{$task->number}",
            'title' => $task->title,
            'project' => $project->label(),
            'project_slug' => $project->slug,
            'slug' => $task->slug,
        ];
    }
}
