<?php

namespace App\Queries;

use App\Http\Resources\AssignmentResource;
use App\Http\Resources\ContactResource;
use App\Http\Resources\NoteResource;
use App\Http\Resources\SubtaskResource;
use App\Http\Resources\TaskResource;
use App\Models\Member;
use App\Models\ProjectActivity;
use App\Models\Task;
use App\Queries\Structure\PersonRef;
use App\Support\Assignees;
use App\Support\WorkspaceAccess;

/**
 * Assembles the Task Peek payload — the full editable context for one task
 * (fields, assignments with ids, subtasks, notes, contacts, recent activity,
 * and the assignee candidate list) — shared by the web and API endpoints.
 */
class TaskPreviewQuery
{
    public function __construct(private TaskCodeQuery $code) {}

    /**
     * @return array{
     *     task: array<string, mixed>,
     *     assignments: array<int, array<string, mixed>>,
     *     subtasks: array<int, array<string, mixed>>,
     *     notes: array<int, array<string, mixed>>,
     *     contacts: array<int, array<string, mixed>>,
     *     activity: array<int, array<string, mixed>>,
     *     team: array<int, array{id: int, name: string, email: ?string, user_id: ?int}>,
     *     join_candidates: array<int, array{id: int, name: string, designation: string|null}>,
     *     comments_count: int,
     *     dependency_candidates: array<int, array<string, mixed>>,
     *     code: array<string, mixed>|null
     * }
     */
    public function data(Task $task): array
    {
        $task->loadMissing(['project', 'notes.user', 'contacts', 'assignments.member', 'subtasks.user', 'dependencies', 'dependents']);
        $task->loadCount('comments');

        // No ->public() here: ProjectActivityRecorder always writes is_public
        // false, so that scope made the Peek's activity list permanently empty.
        // Visibility is already enforced upstream by EnsureProjectVisible.
        $activity = ProjectActivity::query()
            ->where('task_id', $task->id)
            ->codeVisibleTo(request()->user())
            ->with('user')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (ProjectActivity $entry): array => [
                'id' => $entry->id,
                'description' => $entry->description,
                'user' => $entry->user ? ['id' => $entry->user->id, 'name' => $entry->user->name] : null,
                'created_at' => $entry->created_at?->toIso8601String(),
            ]);

        $team = Member::assignableFor($task->project)
            ->get(['id', 'name', 'email', 'user_id'])
            ->map(fn (Member $m): array => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'user_id' => $m->user_id]);

        $viewer = request()->user();

        return [
            'task' => (new TaskResource($task))->resolve(),
            'assignments' => AssignmentResource::collection($task->assignments)->resolve(),
            'subtasks' => SubtaskResource::collection($task->subtasks->sortBy('position')->values())->resolve(),
            'notes' => NoteResource::collection($task->notes)->resolve(),
            'contacts' => ContactResource::collection($task->contacts)->resolve(),
            'activity' => $activity->all(),
            'team' => $team->all(),
            // People of the project's organization who join its team when
            // picked, for whoever runs it (Assignees); empty for anyone else.
            'join_candidates' => Assignees::joinCandidates($viewer, $task->project)
                ->map(PersonRef::of(...))->values()->all(),
            'comments_count' => (int) $task->comments_count,
            // What the task could be made to depend on: the rest of its project.
            'dependency_candidates' => $task->project->tasks()->whereKeyNot($task->id)->get()
                ->map(fn (Task $t): array => TaskResource::reference($t))->values()->all(),
            // The Code section: null when the organization has no git.
            'code' => WorkspaceAccess::canViewProjectCode($viewer, $task->project) ? $this->code->data($task, $viewer) : null,
        ];
    }
}
