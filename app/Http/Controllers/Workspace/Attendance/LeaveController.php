<?php

namespace App\Http\Controllers\Workspace\Attendance;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Attendance\CancelLeaveRequest;
use App\Http\Requests\Attendance\DecideLeaveRequest;
use App\Http\Requests\Attendance\RecordLeaveRequest;
use App\Http\Requests\Attendance\StoreLeaveRequest;
use App\Http\Resources\Attendance\LeaveOverviewResource;
use App\Models\LeaveRequest;
use App\Models\Member;
use App\Queries\Attendance\LeaveQuery;
use App\Services\Attendance\CancelLeaveService;
use App\Services\Attendance\DecideLeaveService;
use App\Services\Attendance\RecordLeaveService;
use App\Services\Attendance\RequestLeaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leave and kaaj: ask, cancel, decide and record (the services), and the
 * page that lists them (LeaveQuery).
 */
class LeaveController extends Controller
{
    use RedirectsWithServiceResult;

    public function index(Request $request, LeaveQuery $query): Response
    {
        return Inertia::render('Attendance/Leave', LeaveOverviewResource::make($query->run($request->user()))->resolve($request));
    }

    public function store(StoreLeaveRequest $request, RequestLeaveService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($request->user(), $request->validated()));
    }

    public function approve(DecideLeaveRequest $request, LeaveRequest $leaveRequest, DecideLeaveService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($leaveRequest, $request->user(), true, $request->note()));
    }

    public function reject(DecideLeaveRequest $request, LeaveRequest $leaveRequest, DecideLeaveService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($leaveRequest, $request->user(), false, $request->note()));
    }

    public function cancel(CancelLeaveRequest $request, LeaveRequest $leaveRequest, CancelLeaveService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($leaveRequest));
    }

    public function record(RecordLeaveRequest $request, Member $member, RecordLeaveService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($member, $request->user(), $request->validated()));
    }
}
