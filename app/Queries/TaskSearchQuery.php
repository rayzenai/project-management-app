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

        // "SG-12" or "12" finds the task by its stable number; the code's key
        // (current or old) narrows it to that project. Only a number a task
        // can have counts; anything longer is searched as text.
        $number = null;
        $codeKey = null;
        if (ctype_digit($q)) {
            $number = $this->taskNumber($q);
        } elseif (preg_match('/^([A-Za-z]{1,10})-(\d+)$/', $q, $m) === 1) {
            $number = $this->taskNumber($m[2]);
            $codeKey = $number === null ? null : strtoupper($m[1]);
        }

        $visibleProjectIds = Project::query()->visibleTo($request->user())->pluck('id');

        $tasks = Task::query()
            ->forActiveProjects()
            ->whereIn('project_id', $visibleProjectIds)
            ->with('project:id,slug,title,key')
            ->where(function ($query) use ($like, $likeOp, $number, $codeKey) {
                $query->where('title', $likeOp, $like)
                    ->orWhere('short_title', $likeOp, $like)
                    ->orWhereRaw("metadata->>'title_np' {$likeOp} ?", [$like]);

                if ($number !== null) {
                    $query->orWhere(function ($byNumber) use ($number, $codeKey) {
                        $byNumber->where('number', $number)
                            ->when($codeKey !== null, fn ($q) => $q->whereHas('project', fn ($p) => $p->where(fn ($k) => $k
                                ->where('key', $codeKey)
                                ->orWhereHas('keyAliases', fn ($a) => $a->where('key', $codeKey)->whereColumn('project_key_aliases.organization_id', 'projects.organization_id')))));
                    });
                }
            })
            ->when($pgsql, fn ($query) => $query
                ->orderByRaw('CASE WHEN number = ? THEN 0 ELSE 1 END', [$number ?? 0])
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
                'number' => $task->number,
                'title' => $task->title,
                'short_title' => $task->short_title,
                'status' => $task->status,
                'status_label' => $task->status_label,
                'project' => $task->project ? [
                    'slug' => $task->project->slug,
                    'title' => $task->project->title,
                    'code' => $task->project->code,
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
     * The digits as a task number, or null when no task can have it (the
     * column is a Postgres integer).
     */
    private function taskNumber(string $digits): ?int
    {
        $number = (int) $digits;

        return $number >= 1 && $number <= 2147483647 ? $number : null;
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
