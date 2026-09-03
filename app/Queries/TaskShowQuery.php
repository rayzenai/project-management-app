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

/**
 * Assembles the single-task payload (task, its notes/contacts, the current
 * user's subtasks, and the assignee candidate list) shared by the Inertia web
 * show page and the JSON API show endpoint.
 */
class TaskShowQuery
{
    /**
     * @return array{
     *     project: array<string, mixed>,
     *     task: array<string, mixed>,
     *     notes: array<int, array<string, mixed>>,
     *     contacts: array<int, array<string, mixed>>,
     *     subtasks: array<int, array<string, mixed>>,
     *     comments: array<int, array<string, mixed>>,
     *     team: array<int, array{id: int, name: string, email: ?string, user_id: ?int}>
     * }
     */
    public function data(Project $project, Task $task, int $userId): array
    {
        $task->load(['assignments.member', 'notes.user', 'contacts', 'project']);

        $team = Member::assignableFor($project)->get(['id', 'name', 'email', 'user_id']);

        $mySubtasks = Subtask::query()
            ->where('task_id', $task->id)
            ->where('user_id', $userId)
            ->orderBy('is_done')
            ->orderBy('position')
            ->get();

        $comments = $task->comments()->with('user')->latest()->get();
        TaskCommentResource::preload($comments);

        return [
            'project' => (new ProjectResource($project))->resolve(),
            'task' => (new TaskResource($task))->resolve(),
            'notes' => NoteResource::collection($task->notes)->resolve(),
            'contacts' => ContactResource::collection($task->contacts)->resolve(),
            'subtasks' => SubtaskResource::collection($mySubtasks)->resolve(),
            'comments' => TaskCommentResource::collection($comments)->resolve(),
            'team' => $team->map(fn (Member $m): array => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'user_id' => $m->user_id])->all(),
        ];
    }
}
