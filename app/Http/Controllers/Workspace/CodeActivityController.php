<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Requests\CodeActivityRequest;
use App\Queries\CodeActivityQuery;
use App\Queries\UnlinkedRepositoriesQuery;
use App\Support\WorkspaceOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The code activity report: who committed what, within what the viewer
 * runs (CodeActivityQuery), and one person's commits for its drawer. For
 * whoever administers the organization in view, the forge repositories no
 * project links yet (`unlinked`, UnlinkedRepositoriesQuery).
 */
class CodeActivityController extends Controller
{
    public function index(CodeActivityRequest $request, CodeActivityQuery $query, UnlinkedRepositoriesQuery $unlinked): Response
    {
        $organizationId = WorkspaceOrganization::chosen($request->user());

        return Inertia::render('Reports/CodeActivity', $query->get($request->user(), $request->filters(), $organizationId) + [
            'filters' => $request->appliedFilters(),
            'unlinked' => $unlinked->forViewer($request->user(), $organizationId),
        ]);
    }

    /**
     * Not route-bound: someone outside the scope, or no one, is an empty
     * list, never a 403 or 404 that would say whether they exist.
     */
    public function person(CodeActivityRequest $request, string $user, CodeActivityQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->commitsOf($request->user(), (int) $user, $request->filters(), organizationId: WorkspaceOrganization::chosen($request->user()))]);
    }
}
