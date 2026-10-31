<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\Attendance\CancelLeaveRequest;
use App\Http\Requests\Attendance\DecideLeaveRequest;
use App\Http\Requests\Attendance\RecordLeaveRequest;
use App\Http\Requests\Attendance\StoreLeaveRequest;
use App\Http\Resources\Attendance\LeaveOverviewResource;
use App\Http\Resources\Attendance\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Models\Member;
use App\Queries\Attendance\LeaveQuery;
use App\Services\Attendance\CancelLeaveService;
use App\Services\Attendance\DecideLeaveService;
use App\Services\Attendance\RecordLeaveService;
use App\Services\Attendance\RequestLeaveService;
use App\Support\ServiceResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\Attendance\LeaveController: the same
 * FormRequests, services, query and resources.
 */
class LeaveController extends Controller
{
    use RespondsWithServiceResult;

    public function index(Request $request, LeaveQuery $query): JsonResponse
    {
        return response()->json(['data' => LeaveOverviewResource::make($query->run($request->user()))]);
    }

    public function store(StoreLeaveRequest $request, RequestLeaveService $service): JsonResponse
    {
        return $this->respond($service->execute($request->user(), $request->validated()), 201);
    }

    public function approve(DecideLeaveRequest $request, LeaveRequest $leaveRequest, DecideLeaveService $service): JsonResponse
    {
        return $this->respond($service->execute($leaveRequest, $request->user(), true, $request->note()));
    }

    public function reject(DecideLeaveRequest $request, LeaveRequest $leaveRequest, DecideLeaveService $service): JsonResponse
    {
        return $this->respond($service->execute($leaveRequest, $request->user(), false, $request->note()));
    }

    public function cancel(CancelLeaveRequest $request, LeaveRequest $leaveRequest, CancelLeaveService $service): JsonResponse
    {
        return $this->respond($service->execute($leaveRequest));
    }

    public function record(RecordLeaveRequest $request, Member $member, RecordLeaveService $service): JsonResponse
    {
        return $this->respond($service->execute($member, $request->user(), $request->validated()), 201);
    }

    private function respond(ServiceResult $result, ?int $created = null): JsonResponse
    {
        return $this->respondWithResult(
            $result,
            $result->data instanceof LeaveRequest ? new LeaveRequestResource($result->data) : null,
            $result->success ? $created : null,
        );
    }
}
