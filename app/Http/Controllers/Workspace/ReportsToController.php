<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Structure\SetReportsToRequest;
use App\Models\Member;
use App\Services\Structure\SetReportsToService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Whom a person reports to, from the organogram (drag, or the row's menu).
 */
class ReportsToController extends Controller
{
    use RedirectsWithServiceResult;

    public function update(SetReportsToRequest $request, Member $member, SetReportsToService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($member, $request->bossId()));
    }
}
