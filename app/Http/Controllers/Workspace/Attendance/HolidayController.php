<?php

namespace App\Http\Controllers\Workspace\Attendance;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Attendance\ManageAttendanceRequest;
use App\Http\Requests\Attendance\SaveHolidayRequest;
use App\Models\Holiday;
use App\Models\Organization;
use App\Services\Attendance\HolidayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * An organization's holidays, on the attendance settings page.
 */
class HolidayController extends Controller
{
    use RedirectsWithServiceResult;

    public function store(SaveHolidayRequest $request, Organization $organization, HolidayService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->add($organization, $request->holiday()));
    }

    public function update(SaveHolidayRequest $request, Organization $organization, Holiday $holiday, HolidayService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->change($holiday, $request->holiday()));
    }

    public function destroy(ManageAttendanceRequest $request, Organization $organization, Holiday $holiday, HolidayService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->remove($holiday));
    }
}
