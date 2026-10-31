<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Requests\Work\PersonWorkRequest;
use App\Http\Requests\Work\TeamWorkRequest;
use App\Http\Requests\Work\WorkDayRequest;
use App\Models\Member;
use App\Queries\Work\PersonWorkQuery;
use App\Queries\Work\TeamWorkQuery;
use App\Queries\Work\WorkDayQuery;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The work reports: today's work by person, a team's week, one person's
 * week. Each page's props are its query's data plus the filters applied.
 */
class WorkController extends Controller
{
    public function day(WorkDayRequest $request, WorkDayQuery $query): Response
    {
        return Inertia::render('Work/Day', $query->run($request->user(), $request->organizationId(), $request->day(), $request->under()) + [
            'filters' => $request->appliedFilters(),
        ]);
    }

    public function team(TeamWorkRequest $request, TeamWorkQuery $query): Response
    {
        [$from, $to] = $request->range();

        return Inertia::render('Work/Team', $query->run($request->user(), $request->organizationId(), $from, $to, $request->under()) + [
            'filters' => $request->appliedFilters(),
        ]);
    }

    public function person(PersonWorkRequest $request, Member $member, PersonWorkQuery $query): Response
    {
        [$from, $to] = $request->range();

        return Inertia::render('Work/Person', $query->run($request->user(), $member, $from, $to) + [
            'filters' => $request->appliedFilters(),
        ]);
    }
}
