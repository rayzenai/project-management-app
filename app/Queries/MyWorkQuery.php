<?php

namespace App\Queries;

use App\Models\Member;
use App\Models\PersonalTodo;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectAssignment;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\BikramSambat;
use App\Support\MentionParser;
use App\Support\WorkingDays;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;

/**
 * My work: one list of a person's own work, read once for the web page, its
 * API twin and the morning email, so all three agree on what is "today".
 *
 * On it: open tasks the person is on (an assignment of their member), open
 * tasks they created that are on others and not on them ("You asked
 * others"), and their own open to-dos (subtasks) that have a date on open
 * tasks, all in active projects they may see (and, on the web, of the switched
 * organization), plus their open private to-dos ("Just for me", whatever the
 * organization; nobody else's, ever). A row's group follows its date: the
 * person's own date on the assignment when set, else the task's (a subtask's
 * or private to-do's own date); a late task (the one overdue rule, by the
 * task's own deadline) is always Today; a snoozed one only Snoozed. Tomorrow
 * is the next working day and Coming up runs to the end of next week
 * (WorkingDays).
 *
 * Beside the list, `joined`: the project teams they were put on lately, so
 * someone added to a project with no tasks yet is told where they land.
 *
 * @phpstan-type MyWorkPerson array{id: int, name: string}
 * @phpstan-type MyWorkUpdate array{author: string, body: string, at: string|null}
 * @phpstan-type MyWorkTask array{id: int, slug: string, number: int, code: string, title: string, status: string, priority: string, deadline_at: string|null, completed_at: string|null, project: MyWorkProject}
 * @phpstan-type MyWorkTaskRow array{kind: 'task'|'subtask', key: string, assignment: array{id: int, is_focused: bool, snoozed_until: string|null, personal_due_at: string|null}|null, task: MyWorkTask, subtask: array{id: int, body: string, due_at: string, is_done: bool}|null, todo: null, due: string|null, days_late: int, people: list<MyWorkPerson>, latest_update: MyWorkUpdate|null}
 * @phpstan-type MyWorkTodoRow array{kind: 'todo', key: string, assignment: null, task: null, subtask: null, todo: array{id: int, title: string, due_on: string|null, started_at: string|null, done_at: string|null}, due: string|null, days_late: int, people: list<MyWorkPerson>, latest_update: null}
 * @phpstan-type MyWorkRow MyWorkTaskRow|MyWorkTodoRow
 * @phpstan-type MyWorkGroup array{key: string, label: string, rows: list<MyWorkRow>}
 * @phpstan-type MyWorkProject array{id: int, slug: string, title: string, code: string, label: string}
 * @phpstan-type MyWorkJoined array{project: MyWorkProject, role: 'leader'|'member', joined_at: string, added_by: string|null}
 * @phpstan-type MyWorkPayload array{today: array{date: string, label: string, bs: string|null, tomorrow: string}, groups: list<MyWorkGroup>, joined: list<MyWorkJoined>}
 */
final class MyWorkQuery
{
    /** The groups, in the order the page shows them. */
    public const GROUPS = [
        'today' => 'Today',
        'tomorrow' => 'Tomorrow',
        'coming_up' => 'Coming up',
        'later' => 'Later',
        'asked_others' => 'You asked others',
        'no_date' => 'No date',
        'snoozed' => 'Snoozed',
        'done' => 'Done',
    ];

    /** Updates and done work reach back this many days. */
    private const RECENT_DAYS = 7;

    /** An update is cut to this many characters. */
    private const UPDATE_LENGTH = 140;

    /** Sorts an undated row after every dated one. */
    private const NO_DATE = '9999-12-31';

    /** A team joined this many days back is still news. */
    private const JOINED_DAYS = 14;

    /** At most this many joined projects. */
    private const JOINED_LIMIT = 5;

    /**
     * `$organizationId` (the web's super-admin switch) keeps it to that
     * organization's projects; null is every one. `$today` defaults to the
     * office's today.
     *
     * @return MyWorkPayload
     */
    public function get(User $user, ?int $organizationId = null, bool $withDone = false, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $member = WorkspaceAccess::memberFor($user);
        $projectIds = Project::query()->visibleTo($user)->inOrganization($organizationId)->active()->select('id');

        $mine = $member === null ? new Collection : $this->onMember($member, $projectIds)->notClosed()->get();
        $asked = Task::query()
            ->whereIn('project_id', $projectIds)
            ->notClosed()
            ->where('created_by', $user->id)
            ->whereHas('assignments')
            ->when($member !== null, fn (Builder $q) => $q->whereDoesntHave('assignments', fn (Builder $a) => $a->where('member_id', $member?->id)))
            ->get();
        $done = $withDone && $member !== null
            ? $this->onMember($member, $projectIds)
                ->complete()
                ->where('completed_at', '>=', $today->subDays(self::RECENT_DAYS))
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->get()
            : new Collection;
        $subtasks = Subtask::query()
            ->where('user_id', $user->id)
            ->where('is_done', false)
            ->whereNotNull('due_at')
            ->whereIn('task_id', Task::query()->whereIn('project_id', $projectIds)->notClosed()->select('id'))
            ->get();
        // Their private to-dos, in one query: open ones, and with done the
        // ones done in the last week.
        $todos = PersonalTodo::query()
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $q->whereNull('done_at')
                ->when($withDone, fn (Builder $q) => $q->orWhere('done_at', '>=', $today->subDays(self::RECENT_DAYS))))
            ->get();
        [$doneTodos, $openTodos] = $todos->partition(fn (PersonalTodo $todo): bool => $todo->done_at !== null);

        // Every task on the page once, so its project, people and latest
        // update are each read in one query whatever the number of rows.
        $tasks = $mine->concat($asked)->concat($done)->keyBy('id');
        $missing = $subtasks->pluck('task_id')->diff($tasks->keys())->unique()->values()->all();

        if ($missing !== []) {
            $tasks = $tasks->concat(Task::query()->whereIn('id', $missing)->get())->keyBy('id');
        }

        foreach ($subtasks as $subtask) {
            $subtask->setRelation('task', $tasks[$subtask->task_id]);
        }

        $tasks->load(['project.program', 'assignments.member']);
        $updates = $this->latestUpdates($tasks->modelKeys(), $today);

        $row = fn (Task $task, ?ProjectAssignment $assignment): array => $this->taskRow($task, $assignment, $updates[$task->id] ?? null, $today);
        $ownAssignment = fn (Task $task): ?ProjectAssignment => $task->assignments->firstWhere('member_id', $member?->id);

        $rows = [
            ...$mine->map(fn (Task $task): array => $row($task, $ownAssignment($task)))->all(),
            ...$subtasks->map(fn (Subtask $subtask): array => $this->subtaskRow($subtask, $updates[$subtask->task_id] ?? null))->all(),
            ...array_map(fn (PersonalTodo $todo): array => $this->todoRow($todo), $openTodos->all()),
        ];

        $days = WorkingDays::forUser($user);
        $nextWorkingDay = $days->nextWorkingDay($today);
        $comingUpEnd = $days->weekStart($today)->addDays(13);
        $grouped = array_fill_keys(array_keys(self::GROUPS), []);

        foreach ($rows as $placed) {
            $grouped[$this->groupOf($placed, $today, $nextWorkingDay, $comingUpEnd)][] = $placed;
        }

        $grouped['asked_others'] = $asked->map(fn (Task $task): array => $row($task, null))->all();
        // Finished tasks and to-dos together, the newest first.
        $grouped['done'] = [
            ...$done->map(fn (Task $task): array => $row($task, $ownAssignment($task)))->all(),
            ...array_map(fn (PersonalTodo $todo): array => $this->todoRow($todo), $doneTodos->all()),
        ];
        usort($grouped['done'], fn (array $a, array $b): int => $this->doneAt($b) <=> $this->doneAt($a));

        $groups = [];

        foreach (self::GROUPS as $key => $label) {
            if ($grouped[$key] === []) {
                continue;
            }

            $groupRows = $grouped[$key];

            if ($key !== 'done') {
                usort($groupRows, fn (array $a, array $b): int => $this->sortKey($a, $key) <=> $this->sortKey($b, $key));
            }

            $groups[] = ['key' => $key, 'label' => $label, 'rows' => array_values($groupRows)];
        }

        return [
            'today' => [
                'date' => $today->toDateString(),
                'label' => $today->format('l, j F'),
                'bs' => BikramSambat::short($today),
                // The next working day: what "Tomorrow" and "snooze until
                // tomorrow" mean (Sunday, on a Friday without attendance).
                'tomorrow' => $nextWorkingDay->toDateString(),
            ],
            'groups' => $groups,
            'joined' => $member === null ? [] : $this->joined($user, $member, $projectIds, $today),
        ];
    }

    /**
     * The teams of those projects the member was put on in the last
     * JOINED_DAYS, newest first, with who put them there when the activity
     * log knows it (the row TeamMembershipObserver::created wrote for this
     * membership). Teams they joined themselves, such as a project they made
     * and manage, are left out. One query for the memberships and their
     * adder, one for the projects (and their programs) and one for the
     * adders' names, whatever the count.
     *
     * @param  Builder<Project>  $projectIds
     * @return list<MyWorkJoined>
     */
    private function joined(User $user, Member $member, Builder $projectIds, CarbonImmutable $today): array
    {
        $addedBy = ProjectActivity::query()
            ->select('user_id')
            ->where('subject_type', (new Team)->getMorphClass())
            ->whereColumn('project_activities.subject_id', 'member_team.team_id')
            ->where('action', ProjectActivity::ACTION_CREATED)
            ->where('changes->member_id', $member->id)
            ->whereColumn('project_activities.created_at', '>=', 'member_team.created_at')
            ->orderBy('id')
            ->limit(1);

        $memberships = TeamMembership::query()
            ->join('project_team', 'project_team.team_id', '=', 'member_team.team_id')
            ->where('member_team.member_id', $member->id)
            ->where('member_team.created_at', '>=', $today->subDays(self::JOINED_DAYS))
            ->whereIn('project_team.project_id', $projectIds)
            ->select(['member_team.role', 'member_team.created_at', 'project_team.project_id'])
            ->addSelect(['added_by_id' => $addedBy])
            ->orderByDesc('member_team.created_at')
            ->orderByDesc('member_team.id')
            ->get()
            ->map(function (TeamMembership $membership): array {
                $addedById = $membership->getAttribute('added_by_id');

                return [
                    'project_id' => (int) $membership->getAttribute('project_id'),
                    'manages' => $membership->role === 'leader',
                    'joined_at' => CarbonImmutable::parse($membership->getAttribute('created_at'))->toIso8601String(),
                    'added_by_id' => $addedById === null ? null : (int) $addedById,
                ];
            })
            ->reject(fn (array $membership): bool => $membership['added_by_id'] === $user->id)
            ->take(self::JOINED_LIMIT)
            ->values();

        if ($memberships->isEmpty()) {
            return [];
        }

        $projects = Project::query()->with('program:id,name')->findMany($memberships->pluck('project_id'))->keyBy('id');
        $adderIds = $memberships->pluck('added_by_id')->filter()->unique()->values()->all();
        $adders = $adderIds === [] ? new Collection : User::query()->whereKey($adderIds)->get(['id', 'name'])->keyBy('id');

        $joined = [];

        foreach ($memberships as $membership) {
            $joined[] = [
                'project' => $this->project($projects[$membership['project_id']]),
                'role' => $membership['manages'] ? 'leader' : 'member',
                'joined_at' => $membership['joined_at'],
                'added_by' => $membership['added_by_id'] === null ? null : $adders->get($membership['added_by_id'])?->name,
            ];
        }

        return $joined;
    }

    /**
     * Tasks of those projects that the member is on (has an assignment).
     *
     * @param  Builder<Project>  $projectIds
     * @return Builder<Task>
     */
    private function onMember(Member $member, Builder $projectIds): Builder
    {
        return Task::query()
            ->whereIn('project_id', $projectIds)
            ->whereHas('assignments', fn (Builder $q) => $q->where('member_id', $member->id));
    }

    /**
     * The newest update (comment) of the last week on each task, in one
     * query, keyed by task id.
     *
     * @param  array<int, int|string>  $taskIds
     * @return SupportCollection<int, TaskComment>
     */
    private function latestUpdates(array $taskIds, CarbonImmutable $today): SupportCollection
    {
        if ($taskIds === []) {
            return collect();
        }

        $newest = TaskComment::query()
            ->selectRaw('max(id)')
            ->whereIn('task_id', $taskIds)
            ->where('created_at', '>=', $today->subDays(self::RECENT_DAYS))
            ->groupBy('task_id');

        return TaskComment::query()
            ->whereIn('id', $newest)
            ->with('user:id,name')
            ->get()
            ->keyBy('task_id');
    }

    /**
     * @return MyWorkTaskRow
     */
    private function taskRow(Task $task, ?ProjectAssignment $assignment, ?TaskComment $update, CarbonImmutable $today): array
    {
        return [
            'kind' => 'task',
            'key' => "task-{$task->id}",
            'assignment' => $assignment === null ? null : [
                'id' => $assignment->id,
                'is_focused' => (bool) $assignment->is_focused,
                'snoozed_until' => $assignment->snoozed_until?->toDateString(),
                'personal_due_at' => $assignment->personal_due_at?->toDateString(),
            ],
            'task' => $this->task($task),
            'subtask' => null,
            'todo' => null,
            'due' => ($assignment->personal_due_at ?? $task->deadline_at)?->toDateString(),
            'days_late' => $this->daysLate($task, $today),
            'people' => $this->people($task),
            'latest_update' => $this->update($update),
        ];
    }

    /**
     * A to-do sits where its own date puts it; it is never late itself.
     *
     * @return MyWorkTaskRow
     */
    private function subtaskRow(Subtask $subtask, ?TaskComment $update): array
    {
        $task = $subtask->task;
        $due = (string) $subtask->due_at?->toDateString();

        return [
            'kind' => 'subtask',
            'key' => "subtask-{$subtask->id}",
            'assignment' => null,
            'task' => $this->task($task),
            'subtask' => ['id' => $subtask->id, 'body' => $subtask->body, 'due_at' => $due, 'is_done' => (bool) $subtask->is_done],
            'todo' => null,
            'due' => $due,
            'days_late' => 0,
            'people' => $this->people($task),
            'latest_update' => $this->update($update),
        ];
    }

    /**
     * A private to-do sits where its own date puts it, like a subtask, and
     * is never late itself; it belongs to no task and has nobody else on it.
     *
     * @return MyWorkTodoRow
     */
    private function todoRow(PersonalTodo $todo): array
    {
        $due = $todo->due_on?->toDateString();

        return [
            'kind' => 'todo',
            'key' => "todo-{$todo->id}",
            'assignment' => null,
            'task' => null,
            'subtask' => null,
            'todo' => [
                'id' => $todo->id,
                'title' => $todo->title,
                'due_on' => $due,
                'started_at' => $todo->started_at?->toIso8601String(),
                'done_at' => $todo->done_at?->toIso8601String(),
            ],
            'due' => $due,
            'days_late' => 0,
            'people' => [],
            'latest_update' => null,
        ];
    }

    /**
     * @return MyWorkTask
     */
    private function task(Task $task): array
    {
        $project = $task->project;

        return [
            'id' => $task->id,
            'slug' => $task->slug,
            'number' => $task->number,
            'code' => "{$project->code}-{$task->number}",
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority ?? 'medium',
            'deadline_at' => $task->deadline_at?->toDateString(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'project' => $this->project($project),
        ];
    }

    /**
     * @return MyWorkProject
     */
    private function project(Project $project): array
    {
        return [
            'id' => $project->id,
            'slug' => $project->slug,
            'title' => $project->title,
            'code' => $project->code,
            'label' => $project->label(),
        ];
    }

    /**
     * Whole days past the task's own deadline, only while it is overdue on
     * `$today` (the one rule, Task::isOverdueOn).
     */
    private function daysLate(Task $task, CarbonImmutable $today): int
    {
        if (! $task->isOverdueOn($today) || $task->deadline_at === null) {
            return 0;
        }

        return max(0, (int) $task->deadline_at->startOfDay()->diffInDays($today));
    }

    /**
     * The task's active people, by name.
     *
     * @return list<MyWorkPerson>
     */
    private function people(Task $task): array
    {
        return array_values($task->assignments
            ->map(fn (ProjectAssignment $assignment): ?Member => $assignment->member)
            ->filter(fn (?Member $member): bool => $member !== null && $member->is_active)
            ->sortBy('name')
            ->map(fn (Member $member): array => ['id' => $member->id, 'name' => $member->name])
            ->all());
    }

    /**
     * @return MyWorkUpdate|null
     */
    private function update(?TaskComment $comment): ?array
    {
        if ($comment === null) {
            return null;
        }

        return [
            'author' => (string) $comment->user?->name,
            'body' => Str::limit(Str::squish(MentionParser::toDisplayText($comment->body)), self::UPDATE_LENGTH, '…'),
            'at' => $comment->created_at?->toIso8601String(),
        ];
    }

    /**
     * When a done row was finished (ISO 8601, all in the office's zone).
     *
     * @param  MyWorkRow  $row
     */
    private function doneAt(array $row): string
    {
        return $row['task']['completed_at'] ?? $row['todo']['done_at'] ?? '';
    }

    /**
     * @param  MyWorkRow  $row
     */
    private function groupOf(array $row, CarbonImmutable $today, CarbonImmutable $nextWorkingDay, CarbonImmutable $comingUpEnd): string
    {
        $snoozedUntil = $row['assignment']['snoozed_until'] ?? null;
        $due = $row['due'];

        return match (true) {
            $snoozedUntil !== null && $snoozedUntil > $today->toDateString() => 'snoozed',
            $row['days_late'] > 0 || ($due !== null && $due <= $today->toDateString()) => 'today',
            $due === null => 'no_date',
            $due <= $nextWorkingDay->toDateString() => 'tomorrow',
            $due <= $comingUpEnd->toDateString() => 'coming_up',
            default => 'later',
        };
    }

    /**
     * Today's late rows first (most late first), then starred rows, then by
     * date and task number.
     *
     * @param  MyWorkRow  $row
     * @return list<int|string>
     */
    private function sortKey(array $row, string $group): array
    {
        return [
            $group === 'today' ? -$row['days_late'] : 0,
            ($row['assignment']['is_focused'] ?? false) ? 0 : 1,
            $row['due'] ?? self::NO_DATE,
            $row['task']['number'] ?? 0,
            $row['key'],
        ];
    }
}
