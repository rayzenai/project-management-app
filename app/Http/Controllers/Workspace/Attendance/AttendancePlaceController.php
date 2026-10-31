<?php

namespace App\Http\Controllers\Workspace\Attendance;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Attendance\ManageAttendanceRequest;
use App\Http\Requests\Attendance\SaveAttendancePlaceRequest;
use App\Models\AttendancePlace;
use App\Models\Organization;
use App\Services\Attendance\AttendancePlaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * An organization's office places, on the attendance settings page.
 */
class AttendancePlaceController extends Controller
{
    use RedirectsWithServiceResult;

    public function store(SaveAttendancePlaceRequest $request, Organization $organization, AttendancePlaceService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->add($organization, $request->place()));
    }

    public function update(SaveAttendancePlaceRequest $request, Organization $organization, AttendancePlace $place, AttendancePlaceService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->change($place, $request->place()));
    }

    public function destroy(ManageAttendanceRequest $request, Organization $organization, AttendancePlace $place, AttendancePlaceService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->remove($place));
    }
}
