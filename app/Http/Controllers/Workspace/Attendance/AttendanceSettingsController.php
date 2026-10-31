<?php

namespace App\Http\Controllers\Workspace\Attendance;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Attendance\EnableAttendanceRequest;
use App\Http\Requests\Attendance\ShowAttendanceSettingsRequest;
use App\Http\Requests\Attendance\UpdateAttendanceSettingsRequest;
use App\Models\Organization;
use App\Models\User;
use App\Queries\Attendance\AttendanceSettingsQuery;
use App\Services\Attendance\EnableAttendanceService;
use App\Services\Attendance\UpdateAttendanceSettingsService;
use App\Support\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An organization's attendance settings page: the switch (super-admins),
 * the hours, office places and networks, holidays.
 */
class AttendanceSettingsController extends Controller
{
    use RedirectsWithServiceResult;

    public function show(ShowAttendanceSettingsRequest $request, AttendanceSettingsQuery $query): Response
    {
        $canEnable = WorkspaceAccess::canEnableAttendance($request->user());

        return Inertia::render('Attendance/Settings', $query->get($request->organization()) + [
            'canEnable' => $canEnable,
            'organizations' => $canEnable ? $query->organizations() : [],
            // The address this request came from, for adding the office's range.
            'yourAddress' => $request->ip(),
        ]);
    }

    public function enable(EnableAttendanceRequest $request, Organization $organization, EnableAttendanceService $service): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return $this->redirectWithResult($service->execute($organization, $request->boolean('enabled'), $actor));
    }

    public function update(UpdateAttendanceSettingsRequest $request, Organization $organization, UpdateAttendanceSettingsService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($organization, $request->settings()));
    }
}
