<?php

namespace App\Http\Controllers\Workspace;

use App\Queries\PlanTrackerQuery;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Specialized 100-Day-Plan tracker view. Filters the workspace down to tasks
 * that carry plan-specific metadata (item_number) and surfaces the government
 * fields the public site cares about.
 */
class PlanTrackerController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('PlanTracker', (new PlanTrackerQuery)->get($request));
    }
}
