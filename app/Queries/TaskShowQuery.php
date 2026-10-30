<?php

namespace App\Queries;

use App\Http\Resources\ContactResource;
use App\Http\Resources\NoteResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\SubtaskResource;
use App\Http\Resources\TaskCommentResource;
use App\Http\Resources\TaskResource;
use App\Models\Member;
use App\Models\Project;
use App\Models\Subtask;
use App\Models\Task;
use App\Support\WorkspaceAccess;

/**
 * Assembles the single-task payload (task, its notes/contacts, the current
 * user's subtasks, the assignee candidate list and who can be mentioned)
 * shared by the Inertia web show page and the JSON API show endpoint.
 */
class TaskShowQuery
{
    public function __construct(private TaskCodeQuery $code) {}

    /**
     * @return array{
     *     project: array<string, mixed>,
     *     task: array<string, mixed>,
     *     notes: array<int, array<string, mixed>>,
     *     contacts: array<int, array<string, mixed>>,
     *     subtasks: array<int, array<string, mixed>>,
     *     comments: array<int, array<string, mixed>>,
     *     team: array<int, array{id: int, name: string, email: ?string, user_id: ?int}>,
     *     mentionCandidates: array<int, array{id: int, name: string, email: ?string, user_id: ?int}>,
     *     dependencyCandidates: array<int, array<string, mixed>>,
     *     code: array<string, mixed>|null
     * }
     */
    public function data(Project $project, Task $task, int $userId): array
    {
        $task->load(['assignments.member', 'notes.user', 'contacts', 'project', 'dependencies', 'dependents']);

        $team = Member::assignableFor($project)->get(['id', 'name', 'email', 'user_id']);
        $person = fn (Member $m): array => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'user_id' => $m->user_id];

        $mySubtasks = Subtask::query()
            ->where('task_id', $task->id)
            ->where('user_id', $userId)
            ->orderBy('is_done')
            ->orderBy('position')
            ->get();

        $comments = $task->comments()->with('user')->latest()->get();
        TaskCommentResource::preload($comments);

        $viewer = request()->user();

        return [
            'project' => (new ProjectResource($project))->resolve(),
            'task' => (new TaskResource($task))->resolve(),
            'notes' => NoteResource::collection($task->notes)->resolve(),
            'contacts' => ContactResource::collection($task->contacts)->resolve(),
            'subtasks' => SubtaskResource::collection($mySubtasks)->resolve(),
            'comments' => TaskCommentResource::collection($comments)->resolve(),
            'team' => $team->map($person)->all(),
            // Who the comment box offers for @mentions: the team, plus the
            // program manager of a program project (who can see it, so a
            // mention notifies them, but is no assignee until they join).
            'mentionCandidates' => $team->concat([$this->programManager($project)])->filter()
                ->unique('id')->sortBy('name')->values()->map($person)->all(),
            // What this task could be made to depend on: the rest of its project.
            'dependencyCandidates' => $project->tasks()->whereKeyNot($task->id)->get()
                ->map(fn (Task $t): array => TaskResource::reference($t))->values()->all(),
            // The Code section: null when the organization has no git.
            'code' => WorkspaceAccess::canViewProjectCode($viewer, $project) ? $this->code->data($task, $viewer) : null,
        ];
    }

    /**
     * The manager of the project's program while they hold its powers (an
     * active login of the project's organization), else null.
     */
    private function programManager(Project $project): ?Member
    {
        $manager = $project->program?->manager;

        return $manager?->user !== null && WorkspaceAccess::managesProgramOf($manager->user, $project) ? $manager : null;
    }
}
