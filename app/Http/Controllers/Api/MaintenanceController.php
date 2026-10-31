<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\MaintenanceRequest;
use App\Models\Organization;
use App\Queries\Maintenance\MaintenanceQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\MaintenanceController.
 */
class MaintenanceController extends Controller
{
    public function show(MaintenanceRequest $request, Organization $organization, MaintenanceQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get((int) $organization->getKey())]);
    }
}
