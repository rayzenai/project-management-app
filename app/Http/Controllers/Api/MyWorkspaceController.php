<?php

namespace App\Http\Controllers\Api;

use App\Queries\MyWorkspaceQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\MyWorkspaceController. Delegates to the shared
 * {@see MyWorkspaceQuery} so the "My Workspace" aggregation is the single source
 * of truth across the Inertia web view and this API feed.
 */
class MyWorkspaceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json((new MyWorkspaceQuery)->get($request));
    }
}
