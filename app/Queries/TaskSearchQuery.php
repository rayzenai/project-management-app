<?php

namespace App\Queries;

use App\Models\Project;
use App\Models\ProjectContact;
use App\Models\ProjectNote;
use App\Models\Task;
use App\Models\WorkspaceNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the fuzzy command-palette search payload shared by the web and JSON
 * API surfaces. Searches tasks, notes, and contacts across every project plus
 * the acting user's personal sticky notes, returning them grouped so the UI can
 * render three columns. Single source of truth for the search query logic.
 */
class TaskSearchQuery
{
    /**
     * @return array{projects: list<array<string, mixed>>, tasks: list<array<string, mixed>>, notes: list<array<string, mixed>>, contacts: list<array<string, mixed>>}
     */
    public function get(Request $request): array
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return ['tasks' => [], 'projects' => [], 'notes' => [], 'contacts' => []];
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $like = '%'.$escaped.'%';
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $likeOp = $pgsql ? 'ILIKE' : 'LIKE';

        $visibleProjectIds = Project::query()->visibleTo($request->user())->pluck('id');

        $tasks = Task::query()
            ->forActiveProjects()
            ->whereIn('project_id', $visibleProjectIds)
            ->with('project:id,slug,title')
            ->where(function ($query) use ($like, $likeOp, $q) {
                $query->where('title', $likeOp, $like)
                    ->orWhere('short_title', $likeOp, $like)
                    ->orWhereRaw("metadata->>'title_np' {$likeOp} ?", [$like]);

                if (ctype_digit($q)) {
                    $query->orWhereRaw("metadata->>'item_number' = ?", [(int) $q]);
                }
            })
            ->when($pgsql, fn ($query) => $query
                ->orderByRaw("CASE WHEN CAST(metadata->>'item_number' AS INTEGER) = ? THEN 0 ELSE 1 END", [(int) $q])
                ->orderByRaw('similarity(title, ?) DESC', [$q]))
            ->limit(15)
            ->get();

        $matchedProjects = Project::query()
            ->active()
            ->whereIn('id', $visibleProjectIds)
            ->withCount('tasks')
            ->where(function ($query) use ($likeOp, $like) {
                $query->where('title', $likeOp, $like)->orWhere('slug', $likeOp, $like);
            })
            ->orderBy('title')
            ->limit(5)
            ->get();

        $notes = ProjectNote::query()
            ->with(['task:id,slug,title,project_id', 'task.project:id,slug,title'])
            ->whereIn('task_id', Task::query()->whereIn('project_id', $visibleProjectIds)->select('id'))
            ->where('body', $likeOp, $like)
            ->latest()
            ->limit(15)
            ->get();

        $stickies = WorkspaceNote::query()
            ->where('user_id', $request->user()->id)
            ->where(function ($query) use ($like, $likeOp) {
                $query->where('title', $likeOp, $like)
                    ->orWhere('body', $likeOp, $like);
            })
            ->latest()
            ->limit(15)
            ->get();

        $contacts = ProjectContact::query()
            ->with(['task:id,slug,title,project_id', 'task.project:id,slug,title'])
            ->whereIn('task_id', Task::query()->whereIn('project_id', $visibleProjectIds)->select('id'))
            ->where(function ($query) use ($like, $likeOp) {
                $query->where('name', $likeOp, $like)
                    ->orWhere('organization', $likeOp, $like)
                    ->orWhere('role', $likeOp, $like)
                    ->orWhere('email', $likeOp, $like)
                    ->orWhere('phone', $likeOp, $like);
            })
            ->latest()
            ->limit(15)
            ->get();

        return [
            'projects' => array_values($matchedProjects->map(fn (Project $project): array => [
                'id' => $project->id,
                'slug' => $project->slug,
                'title' => $project->title,
                'tasks_count' => (int) $project->tasks_count,
            ])->all()),
            'tasks' => array_values($tasks->map(fn (Task $task): array => [
                'id' => $task->id,
                'slug' => $task->slug,
                'item_number' => $task->item_number,
                'title' => $task->title,
                'short_title' => $task->short_title,
                'status' => $task->status,
                'status_label' => $task->status_label,
                'project' => $task->project ? [
                    'slug' => $task->project->slug,
                    'title' => $task->project->title,
                ] : null,
            ])->all()),
            'notes' => array_merge(
                $notes->map(fn (ProjectNote $note): array => [
                    'kind' => 'task',
                    'id' => $note->id,
                    'title' => null,
                    'body' => $note->body,
                    'task' => $this->taskRef($note->task),
                ])->all(),
                $stickies->map(fn (WorkspaceNote $sticky): array => [
                    'kind' => 'sticky',
                    'id' => $sticky->id,
                    'title' => $sticky->title,
                    'body' => $sticky->body,
                    'task' => null,
                ])->all(),
            ),
            'contacts' => array_values($contacts->map(fn (ProjectContact $contact): array => [
                'id' => $contact->id,
                'name' => $contact->name,
                'role' => $contact->role,
                'organization' => $contact->organization,
                'task' => $this->taskRef($contact->task),
            ])->all()),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function taskRef(?Task $task): ?array
    {
        if (! $task) {
            return null;
        }

        return [
            'id' => $task->id,
            'slug' => $task->slug,
            'title' => $task->title,
            'project' => $task->project ? [
                'slug' => $task->project->slug,
                'title' => $task->project->title,
            ] : null,
        ];
    }
}
