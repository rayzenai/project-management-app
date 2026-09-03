<?php

namespace App\Http\Controllers\Api;

use App\Queries\DashboardQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\DashboardController. Delegates to the shared
 * {@see DashboardQuery} so the dashboard aggregation is the single source of
 * truth across the Inertia web view and this API feed.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json((new DashboardQuery)->get($request));
    }
}
