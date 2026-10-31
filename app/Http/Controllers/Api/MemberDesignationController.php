<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\Structure\SetDesignationRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Services\Structure\SetDesignationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\MemberDesignationController.
 */
class MemberDesignationController extends Controller
{
    use RespondsWithServiceResult;

    public function update(SetDesignationRequest $request, Member $member, SetDesignationService $service): JsonResponse
    {
        $result = $service->execute($member, $request->designationId());

        return $this->respondWithResult(
            $result,
            $result->data instanceof Member ? new MemberResource($result->data->load(MemberResource::STRUCTURE_RELATIONS)) : null,
        );
    }
}
