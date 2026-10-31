<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\Structure\DeleteDesignationRequest;
use App\Http\Requests\Structure\StoreDesignationRequest;
use App\Http\Requests\Structure\UpdateDesignationRequest;
use App\Http\Resources\DesignationResource;
use App\Models\Designation;
use App\Models\Organization;
use App\Services\Structure\CreateDesignationService;
use App\Services\Structure\DeleteDesignationService;
use App\Services\Structure\UpdateDesignationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\DesignationController.
 */
class DesignationController extends Controller
{
    use RespondsWithServiceResult;

    public function store(StoreDesignationRequest $request, Organization $organization, CreateDesignationService $service): JsonResponse
    {
        $result = $service->execute($organization, $request->designation());

        return $this->respondWithResult(
            $result,
            $result->data instanceof Designation ? new DesignationResource($result->data) : null,
            $result->success ? 201 : null,
        );
    }

    public function update(UpdateDesignationRequest $request, Designation $designation, UpdateDesignationService $service): JsonResponse
    {
        $result = $service->execute($designation, $request->changes());

        return $this->respondWithResult(
            $result,
            $result->data instanceof Designation ? new DesignationResource($result->data) : null,
        );
    }

    public function destroy(DeleteDesignationRequest $request, Designation $designation, DeleteDesignationService $service): JsonResponse
    {
        return $this->respondWithResult($service->execute($designation));
    }
}
