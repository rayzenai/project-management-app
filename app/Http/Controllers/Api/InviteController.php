<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\InvitePeopleRequest;
use App\Models\User;
use App\Services\Workspace\InvitePeopleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\InviteController: the same preview, and the
 * send answered as `{ message, data: { invited, skipped } }`.
 */
class InviteController extends Controller
{
    use RespondsWithServiceResult;

    public function preview(InvitePeopleRequest $request, InvitePeopleService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $service->preview($user, $request->organization(), $request->previewPeople(), $request->withoutLogin());

        return response()->json(
            $result->success ? $result->data : ['message' => $result->message, 'errors' => $result->data],
            $result->success ? 200 : (int) $result->code,
        );
    }

    public function store(InvitePeopleRequest $request, InvitePeopleService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respondWithResult($service->execute($user, $request->organization(), $request->people(), $request->withoutLogin()));
    }
}
