<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\Attendance\EnableAttendanceRequest;
use App\Http\Requests\Attendance\ShowAttendanceSettingsRequest;
use App\Http\Requests\Attendance\UpdateAttendanceSettingsRequest;
use App\Http\Resources\Attendance\SettingResource;
use App\Models\AttendanceSetting;
use App\Models\Organization;
use App\Models\User;
use App\Queries\Attendance\AttendanceSettingsQuery;
use App\Services\Attendance\EnableAttendanceService;
use App\Services\Attendance\UpdateAttendanceSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\Attendance\AttendanceSettingsController.
 */
class AttendanceSettingsController extends Controller
{
    use RespondsWithServiceResult;

    public function show(ShowAttendanceSettingsRequest $request, Organization $organization, AttendanceSettingsQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get($request->organization())]);
    }

    public function enable(EnableAttendanceRequest $request, Organization $organization, EnableAttendanceService $service): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $result = $service->execute($organization, $request->boolean('enabled'), $actor);

        return $this->respondWithResult($result, self::setting($result->data));
    }

    public function update(UpdateAttendanceSettingsRequest $request, Organization $organization, UpdateAttendanceSettingsService $service): JsonResponse
    {
        $result = $service->execute($organization, $request->settings());

        return $this->respondWithResult($result, self::setting($result->data));
    }

    private static function setting(mixed $data): ?SettingResource
    {
        return $data instanceof AttendanceSetting ? new SettingResource($data) : null;
    }
}
