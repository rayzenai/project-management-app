<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\Attendance\ManageAttendanceRequest;
use App\Http\Requests\Attendance\SaveAttendancePlaceRequest;
use App\Http\Resources\Attendance\PlaceResource;
use App\Models\AttendancePlace;
use App\Models\Organization;
use App\Services\Attendance\AttendancePlaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\Attendance\AttendancePlaceController.
 */
class AttendancePlaceController extends Controller
{
    use RespondsWithServiceResult;

    public function store(SaveAttendancePlaceRequest $request, Organization $organization, AttendancePlaceService $service): JsonResponse
    {
        $result = $service->add($organization, $request->place());

        return $this->respondWithResult($result, self::resource($result->data), $result->success ? 201 : null);
    }

    public function update(SaveAttendancePlaceRequest $request, Organization $organization, AttendancePlace $place, AttendancePlaceService $service): JsonResponse
    {
        $result = $service->change($place, $request->place());

        return $this->respondWithResult($result, self::resource($result->data));
    }

    public function destroy(ManageAttendanceRequest $request, Organization $organization, AttendancePlace $place, AttendancePlaceService $service): JsonResponse
    {
        return $this->respondWithResult($service->remove($place));
    }

    private static function resource(mixed $data): ?PlaceResource
    {
        return $data instanceof AttendancePlace ? new PlaceResource($data) : null;
    }
}
