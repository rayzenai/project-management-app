<?php

namespace App\Http\Controllers\Workspace;

use App\Models\GitConnection;
use App\Services\Git\GitIngest;
use App\Support\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The forge's link back: `TP-12` written on the forge opens
 * /workspace/go/{forge organization}/TP-12 (the external tracker format a
 * linked repository gets). The key resolves within the office connected to
 * that forge organization. Anyone who can't see the task gets a 404, never a
 * 403, so the route can't confirm that a task exists.
 */
class GoToTaskController extends Controller
{
    public function __invoke(Request $request, GitIngest $ingest, string $org, string $index): RedirectResponse
    {
        abort_unless(preg_match('/^[A-Za-z]{1,10}-[1-9][0-9]{0,8}$/', $index) === 1, 404);

        // The same forge organization name can be connected on two forges.
        $organizationIds = GitConnection::query()
            ->whereRaw('lower(owner) = ?', [mb_strtolower($org)])
            ->orderBy('id')
            ->pluck('organization_id');

        foreach ($organizationIds as $organizationId) {
            $task = $ingest->task((int) $organizationId, $index);

            if ($task !== null && WorkspaceAccess::canViewProject($request->user(), $task->project)) {
                return redirect()->route('workspace.tasks.show', ['project' => $task->project->slug, 'task' => $task->slug]);
            }
        }

        abort(404);
    }
}
