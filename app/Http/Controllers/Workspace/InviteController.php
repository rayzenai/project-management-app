<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\InvitePeopleRequest;
use App\Models\User;
use App\Services\Workspace\InvitePeopleService;
use App\Support\InvitePlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * The People page's "Invite people": a JSON preview for the dialog as the
 * admin types, and the send, which flashes how many were invited.
 */
class InviteController extends Controller
{
    use RedirectsWithServiceResult;

    public function preview(InvitePeopleRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(InvitePlan::preview($user, $request->organization(), $request->previewPeople(), $request->withoutLogin()));
    }

    public function store(InvitePeopleRequest $request, InvitePeopleService $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->redirectWithResult($service->execute($user, $request->organization(), $request->people(), $request->withoutLogin()));
    }
}
