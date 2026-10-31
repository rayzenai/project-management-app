<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Structure\OrganogramRequest;
use App\Models\Organization;
use App\Queries\Structure\OrganogramQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\OrganogramController.
 */
class OrganogramController extends Controller
{
    public function show(OrganogramRequest $request, Organization $organization, OrganogramQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get($request->user(), (int) $organization->getKey())]);
    }
}
