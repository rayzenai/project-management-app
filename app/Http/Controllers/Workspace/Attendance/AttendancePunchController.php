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
 * The top bar's clock button. The page's `clock` prop (shared) carries
 * the day afterwards.
 */
class AttendancePunchController extends Controller
{
    use RedirectsWithServiceResult;

    public function store(PunchRequest $request, PunchService $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $service->punch($user, $request->string('kind')->value(), $request->context(AttendancePunch::SOURCE_WEB));

        // The clock's popover asks for the note itself: no error toast on top.
        if ($result->failed() && is_array($result->data) && isset($result->data['note'])) {
            return back()->withErrors($result->data);
        }

        return $this->redirectWithResult($result);
    }
}
