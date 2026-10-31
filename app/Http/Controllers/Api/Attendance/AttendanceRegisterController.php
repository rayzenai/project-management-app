<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Requests\Attendance\AttendanceRegisterRequest;
use App\Http\Requests\Attendance\AttendanceSummaryRequest;
use App\Models\Organization;
use App\Queries\Attendance\AttendanceRegisterQuery;
use App\Queries\Attendance\AttendanceSummaryQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\Attendance\AttendanceRegisterController, plus the
 * attendance totals over a range (AttendanceSummaryQuery). The organization
 * comes from the path; the FormRequests check it.
 */
class AttendanceRegisterController extends Controller
{
    public function index(AttendanceRegisterRequest $request, Organization $organization, AttendanceRegisterQuery $query): JsonResponse
    {
        return response()->json([
            'data' => $query->run($request->user(), $request->organizationId(), $request->day(), $request->under()),
            'filters' => $request->appliedFilters(),
        ]);
    }

    public function summary(AttendanceSummaryRequest $request, Organization $organization, AttendanceSummaryQuery $query): JsonResponse
    {
        [$from, $to] = $request->range();

        return response()->json([
            'data' => $query->run($request->user(), $request->organizationId(), $from, $to, $request->under()),
            'filters' => $request->appliedFilters(),
        ]);
    }
}
