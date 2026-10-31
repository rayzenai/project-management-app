<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\Attendance\PunchRequest;
use App\Http\Requests\Attendance\ShowMyAttendanceRequest;
use App\Http\Resources\Attendance\PunchResource;
use App\Models\AttendancePunch;
use App\Models\User;
use App\Queries\Attendance\MyAttendanceQuery;
use App\Services\Attendance\PunchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\Attendance\AttendancePunchController, plus
 * the day the web shares as its `attendance` prop. Punches from here are
 * the app's.
 */
class AttendancePunchController extends Controller
{
    use RespondsWithServiceResult;

    public function me(ShowMyAttendanceRequest $request, MyAttendanceQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get($request->user())]);
    }

    public function store(PunchRequest $request, PunchService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $service->punch($user, $request->string('kind')->value(), $request->context(AttendancePunch::SOURCE_APP));

        /** @var array{punch: AttendancePunch, next: list<string>}|null $data */
        $data = $result->success ? $result->data : null;

        return $this->respondWithResult(
            $result,
            $data === null ? null : ['punch' => new PunchResource($data['punch']), 'next' => $data['next']],
            $result->success ? 201 : null,
        );
    }
}
