<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Requests\CodeActivityRequest;
use App\Queries\CodeActivityQuery;
use App\Support\WorkspaceOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The code activity report: who committed what, within what the viewer
 * runs (CodeActivityQuery), and one person's commits for its drawer.
 */
class CodeActivityController extends Controller
{
    public function index(CodeActivityRequest $request, CodeActivityQuery $query): Response
    {
        return Inertia::render('Reports/CodeActivity', $query->get($request->user(), $request->filters(), WorkspaceOrganization::chosen($request->user())) + [
            'filters' => $request->appliedFilters(),
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
