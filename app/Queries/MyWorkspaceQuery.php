<?php

namespace App\Queries;

use App\Http\Resources\AssignmentResource;
use App\Http\Resources\ContactResource;
use App\Http\Resources\NoteResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\SubtaskResource;
use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectContact;
use App\Models\ProjectNote;
use App\Models\Subtask;
use App\Models\Task;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Assembles the "My Workspace" payload shared by the Inertia web view and the
 * JSON API feed. Single source of truth for the acting member's open
 * assignments, snoozed count, todos, and their recent notes/contacts.
 */
class MyWorkspaceQuery
{
    /**
     * @return array<string, mixed>
     */
    public function get(Request $request): array
    {
        $user = $request->user();
        $member = Member::forUser($user);

        $now = now()->startOfDay();

        $assignments = $this->whereHasTask(
            ProjectAssignment::query()
                ->with(['task.project', 'task.assignments.member', 'member'])
                ->where('member_id', $member->id),
            fn ($q) => $q->incomplete()->forActiveProjects()
                ->whereIn('project_id', Project::query()->visibleTo($user)->select('id')),
        )
            ->where(function ($q) use ($now) {
                $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', $now);
            })
            ->orderByDesc('is_focused')
            ->orderByDesc('created_at')
            ->get();

        $snoozedCount = $this->whereHasTask(
            ProjectAssignment::query()
                ->where('member_id', $member->id)
                ->where('snoozed_until', '>', $now),
            fn ($q) => $q->forActiveProjects()
                ->whereIn('project_id', Project::query()->visibleTo($user)->select('id')),
        )->count();

        $openTaskIds = $assignments
            ->filter(fn ($a) => $a->task && ! $a->task->isComplete())
            ->pluck('task_id')
            ->unique()
            ->values()
            ->all();

        $recentNotes = $openTaskIds
            ? ProjectNote::query()
                ->with(['user', 'task.project'])
                ->whereIn('task_id', $openTaskIds)
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(15)
                ->get()
            : collect();

        $recentContacts = $openTaskIds
            ? ProjectContact::query()
                ->with(['task.project'])
                ->whereIn('task_id', $openTaskIds)
                ->orderByDesc('created_at')
                ->limit(15)
                ->get()
            : collect();

        $projects = Project::query()->visibleTo($user)->active()->orderBy('title')->get(['id', 'slug', 'title']);

        $teamMembers = Member::query()->active()->orderBy('name')->get(['id', 'name', 'email', 'user_id']);

        $openTodos = $this->whereHasTask(
            Subtask::query()
                ->with(['task.project'])
                ->where('user_id', $user->id)
                ->where('is_done', false),
            fn ($q) => $q->forActiveProjects(),
        )
            ->orderByRaw('due_at IS NULL ASC')
            ->orderBy('due_at')
            ->orderBy('position')
            ->get();

        return [
            'assignments' => AssignmentResource::collection($assignments)->resolve(),
            'snoozedCount' => $snoozedCount,
            'openTodos' => SubtaskResource::collection($openTodos)->resolve(),
            'recentNotes' => NoteResource::collection($recentNotes)->resolve(),
            'recentContacts' => ContactResource::collection($recentContacts)->resolve(),
            'projects' => ProjectResource::collection($projects)->resolve(),
            'team' => $teamMembers->map(fn (Member $m) => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'user_id' => $m->user_id])->all(),
        ];
    }

    /**
     * `whereHas('task', ...)` on a query whose model has a `task` relation.
     * Typed so the constraint closure receives a `Builder<Task>` and the Task
     * scopes (`incomplete`, `forActiveProjects`) resolve — `whereHas` cannot
     * infer the related model from the relation name alone.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(Builder<Task>): mixed  $constraint
     * @return Builder<TModel>
     */
    private function whereHasTask(Builder $query, Closure $constraint): Builder
    {
        return $query->whereHas('task', $constraint);
    }
}
