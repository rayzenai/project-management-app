<?php

namespace App\Http\Controllers\Workspace\Attendance;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Attendance\ManageAttendanceRequest;
use App\Http\Requests\Attendance\SaveAttendanceNetworkRequest;
use App\Models\AttendanceNetwork;
use App\Models\Organization;
use App\Services\Attendance\AttendanceNetworkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * An organization's office network ranges, on the attendance settings page.
 */
class AttendanceNetworkController extends Controller
{
    use RedirectsWithServiceResult;

    public function store(SaveAttendanceNetworkRequest $request, Organization $organization, AttendanceNetworkService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->add($organization, $request->network()));
    }

    public function update(SaveAttendanceNetworkRequest $request, Organization $organization, AttendanceNetwork $network, AttendanceNetworkService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->change($network, $request->network()));
    }

    public function destroy(ManageAttendanceRequest $request, Organization $organization, AttendanceNetwork $network, AttendanceNetworkService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->remove($network));
    }
}
