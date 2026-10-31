<?php

namespace App\Http\Controllers\Workspace\Attendance;

use App\Http\Requests\Attendance\AttendanceRegisterRequest;
use App\Queries\Attendance\AttendanceRegisterQuery;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The attendance register: one day of an organization, or of someone and
 * everyone below them (AttendanceRegisterQuery).
 */
class AttendanceRegisterController extends Controller
{
    public function index(AttendanceRegisterRequest $request, AttendanceRegisterQuery $query): Response
    {
        return Inertia::render('Attendance/Register', [
            ...$query->run($request->user(), $request->organizationId(), $request->day(), $request->under()),
            'filters' => $request->appliedFilters(),
        ]);
    }
}
