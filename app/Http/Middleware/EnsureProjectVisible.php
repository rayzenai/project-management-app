<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectContact;
use App\Models\ProjectNote;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskComment;
use App\Support\WorkspaceAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Central project-visibility gate for every route that acts on a project, a
 * task, or a task-child (comment, note, contact, assignment, subtask).
 *
 * Route-model binding has already run by the time this middleware executes, so
 * the route parameters are model INSTANCES. We resolve the parent Project from
 * the first recognized bound model and 403 anyone who cannot view it — enforced
 * in ONE place so future endpoints can't forget the check.
 *
 * Applied ONLY to routes that bind one of the recognized models; if no project
 * can be resolved the request passes through unchanged (defensive fail-open so
 * an unexpected route shape never crashes).
 */
class EnsureProjectVisible
{
    public function handle(Request $request, Closure $next): Response
    {
        $project = $this->resolveProject($request);

        if ($project !== null) {
            abort_unless(WorkspaceAccess::canViewProject($request->user(), $project), 403);
        }

        return $next($request);
    }

    /**
     * Map the first recognized bound route parameter to its parent Project.
     * Task children are walked through their `task` relation (loaded on demand);
     * a Task maps through `project`. Returns null when nothing maps.
     */
    private function resolveProject(Request $request): ?Project
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Project) {
                return $parameter;
            }

            if ($parameter instanceof Task) {
                return $parameter->loadMissing('project')->project;
            }

            if (
                $parameter instanceof ProjectAssignment
                || $parameter instanceof ProjectNote
                || $parameter instanceof ProjectContact
                || $parameter instanceof Subtask
                || $parameter instanceof TaskComment
            ) {
                return $parameter->loadMissing('task.project')->task?->project;
            }
        }

        return null;
    }
}
