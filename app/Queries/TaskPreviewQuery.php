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

/**
 * Assembles the Task Peek payload — the full editable context for one task
 * (fields, assignments with ids, subtasks, notes, contacts, recent activity,
 * and the assignee candidate list) — shared by the web and API endpoints.
 */
class TaskPreviewQuery
{
    /**
     * @return array{
     *     task: array<string, mixed>,
     *     assignments: array<int, array<string, mixed>>,
     *     subtasks: array<int, array<string, mixed>>,
     *     notes: array<int, array<string, mixed>>,
     *     contacts: array<int, array<string, mixed>>,
     *     activity: array<int, array<string, mixed>>,
     *     team: array<int, array{id: int, name: string, email: ?string, user_id: ?int}>,
     *     comments_count: int
     * }
     */
    public function data(Task $task): array
    {
        $task->loadMissing(['project', 'notes.user', 'contacts', 'assignments.member', 'subtasks.user']);
        $task->loadCount('comments');

        $activity = ProjectActivity::query()
            ->where('task_id', $task->id)
            ->public()
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

        return [
            'task' => (new TaskResource($task))->resolve(),
            'assignments' => AssignmentResource::collection($task->assignments)->resolve(),
            'subtasks' => SubtaskResource::collection($task->subtasks->sortBy('position')->values())->resolve(),
            'notes' => NoteResource::collection($task->notes)->resolve(),
            'contacts' => ContactResource::collection($task->contacts)->resolve(),
            'activity' => $activity->all(),
            'team' => $team->all(),
            'comments_count' => (int) $task->comments_count,
        ];
    }
}
