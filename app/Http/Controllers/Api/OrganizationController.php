<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Queries\OrganizationIndexQuery;
use App\Services\Workspace\DeleteOrganizationService;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\OrganizationController (super-admin only).
 */
class OrganizationController extends Controller
{
    use RespondsWithServiceResult;

    public function index(Request $request, OrganizationIndexQuery $query): JsonResponse
    {
        abort_unless(WorkspaceAccess::canManageOrganizations($request->user()), 403);

        return response()->json($query->data());
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $organization = Organization::create($request->validated());

        return response()->json([
            'message' => 'Organization created.',
            'data' => new OrganizationResource($organization),
        ], 201);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): JsonResponse
    {
        $organization->update($request->validated());

        return response()->json([
            'message' => 'Organization renamed.',
            'data' => new OrganizationResource($organization),
        ]);
    }

    public function destroy(Request $request, Organization $organization, DeleteOrganizationService $service): JsonResponse
    {
        abort_unless(WorkspaceAccess::canManageOrganizations($request->user()), 403);

        return $this->respondWithResult($service->execute($organization));
    }
}
