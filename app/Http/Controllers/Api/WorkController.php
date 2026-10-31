<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Work\PersonWorkRequest;
use App\Http\Requests\Work\TeamWorkRequest;
use App\Http\Requests\Work\WorkDayRequest;
use App\Models\Member;
use App\Models\Organization;
use App\Queries\Work\PersonWorkQuery;
use App\Queries\Work\TeamWorkQuery;
use App\Queries\Work\WorkDayQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\WorkController: `{data, filters}`, `data`
 * being the same query's answer as the page's props. `{organization}` is
 * bound (an unknown one is a 404); the request reads it from the route.
 */
class WorkController extends Controller
{
    public function day(WorkDayRequest $request, Organization $organization, WorkDayQuery $query): JsonResponse
    {
        return response()->json([
            'data' => $query->run($request->user(), $request->organizationId(), $request->day(), $request->under()),
            'filters' => $request->appliedFilters(),
        ]);
    }

    public function team(TeamWorkRequest $request, Organization $organization, TeamWorkQuery $query): JsonResponse
    {
        [$from, $to] = $request->range();

        return response()->json([
            'data' => $query->run($request->user(), $request->organizationId(), $from, $to, $request->under()),
            'filters' => $request->appliedFilters(),
        ]);
    }

    public function person(PersonWorkRequest $request, Member $member, PersonWorkQuery $query): JsonResponse
    {
        [$from, $to] = $request->range();

        return response()->json([
            'data' => $query->run($request->user(), $member, $from, $to),
            'filters' => $request->appliedFilters(),
        ]);
    }
}
