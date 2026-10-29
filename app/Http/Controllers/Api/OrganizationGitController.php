<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\DisableGitRequest;
use App\Http\Requests\EnableGitRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Workspace\DisableGitService;
use App\Services\Workspace\EnableGitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\OrganizationGitController (super-admin only).
 */
class OrganizationGitController extends Controller
{
    use RespondsWithServiceResult;

    public function store(EnableGitRequest $request, Organization $organization, EnableGitService $service): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{base_url: string, owner: string, token: string} $input */
        $input = $request->validated();
        $result = $service->execute($organization, $actor, $input);

        return $this->respondWithResult(
            $result,
            $result->success ? new OrganizationResource($organization->load('gitConnection')) : null,
        );
    }

    public function destroy(DisableGitRequest $request, Organization $organization, DisableGitService $service): JsonResponse
    {
        return $this->respondWithResult($service->execute($organization));
    }
}
