<?php

namespace App\Http\Controllers\Workspace\Attendance;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Attendance\PunchRequest;
use App\Models\AttendancePunch;
use App\Models\User;
use App\Services\Attendance\PunchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * The top bar's clock button. The page's `attendance` prop (shared) carries
 * the day afterwards.
 */
class AttendancePunchController extends Controller
{
    use RedirectsWithServiceResult;

    public function store(PunchRequest $request, PunchService $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->redirectWithResult(
            $service->punch($user, $request->string('kind')->value(), $request->context(AttendancePunch::SOURCE_WEB)),
        );
    }
}
