<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\Attendance\ManageAttendanceRequest;
use App\Http\Requests\Attendance\SaveAttendanceNetworkRequest;
use App\Http\Resources\Attendance\NetworkResource;
use App\Models\AttendanceNetwork;
use App\Models\Organization;
use App\Services\Attendance\AttendanceNetworkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\Attendance\AttendanceNetworkController.
 */
class AttendanceNetworkController extends Controller
{
    use RespondsWithServiceResult;

    public function store(SaveAttendanceNetworkRequest $request, Organization $organization, AttendanceNetworkService $service): JsonResponse
    {
        $result = $service->add($organization, $request->network());

        return $this->respondWithResult($result, self::resource($result->data), $result->success ? 201 : null);
    }

    public function update(SaveAttendanceNetworkRequest $request, Organization $organization, AttendanceNetwork $network, AttendanceNetworkService $service): JsonResponse
    {
        $result = $service->change($network, $request->network());

        return $this->respondWithResult($result, self::resource($result->data));
    }

    public function destroy(ManageAttendanceRequest $request, Organization $organization, AttendanceNetwork $network, AttendanceNetworkService $service): JsonResponse
    {
        return $this->respondWithResult($service->remove($network));
    }

    private static function resource(mixed $data): ?NetworkResource
    {
        return $data instanceof AttendanceNetwork ? new NetworkResource($data) : null;
    }
}
