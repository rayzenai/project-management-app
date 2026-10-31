<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\Structure\SetReportsToRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Services\Structure\SetReportsToService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\ReportsToController.
 */
class ReportsToController extends Controller
{
    use RespondsWithServiceResult;

    public function update(SetReportsToRequest $request, Member $member, SetReportsToService $service): JsonResponse
    {
        $result = $service->execute($member, $request->bossId());

        return $this->respondWithResult(
            $result,
            $result->data instanceof Member ? new MemberResource($result->data->load(MemberResource::STRUCTURE_RELATIONS)) : null,
        );
    }
}
