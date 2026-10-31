<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\Attendance\ManageAttendanceRequest;
use App\Http\Requests\Attendance\SaveHolidayRequest;
use App\Http\Resources\Attendance\HolidayResource;
use App\Models\Holiday;
use App\Models\Organization;
use App\Services\Attendance\HolidayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\Attendance\HolidayController.
 */
class HolidayController extends Controller
{
    use RespondsWithServiceResult;

    public function store(SaveHolidayRequest $request, Organization $organization, HolidayService $service): JsonResponse
    {
        $result = $service->add($organization, $request->holiday());

        return $this->respondWithResult($result, self::resource($result->data), $result->success ? 201 : null);
    }

    public function update(SaveHolidayRequest $request, Organization $organization, Holiday $holiday, HolidayService $service): JsonResponse
    {
        $result = $service->change($holiday, $request->holiday());

        return $this->respondWithResult($result, self::resource($result->data));
    }

    public function destroy(ManageAttendanceRequest $request, Organization $organization, Holiday $holiday, HolidayService $service): JsonResponse
    {
        return $this->respondWithResult($service->remove($holiday));
    }

    private static function resource(mixed $data): ?HolidayResource
    {
        return $data instanceof Holiday ? new HolidayResource($data) : null;
    }
}
