<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Structure\SetDesignationRequest;
use App\Models\Member;
use App\Services\Structure\SetDesignationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * A person's designation, from the organogram (the row's menu).
 */
class MemberDesignationController extends Controller
{
    use RedirectsWithServiceResult;

    public function update(SetDesignationRequest $request, Member $member, SetDesignationService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($member, $request->designationId()));
    }
}
