<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Requests\MaintenanceRequest;
use App\Queries\Maintenance\MaintenanceQuery;
use App\Queries\Structure\OrganizationChoicesQuery;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The maintenance page: what needs looking at in the viewer's organization
 * (a super-admin picks one).
 */
class MaintenanceController extends Controller
{
    public function index(MaintenanceRequest $request, MaintenanceQuery $query, OrganizationChoicesQuery $organizations): Response
    {
        $organizationId = $request->organizationId();

        return Inertia::render('Maintenance/Index', [
            'maintenance' => $organizationId === null ? null : $query->get($organizationId),
            'organizations' => $organizations->options($request->user()),
        ]);
    }
}
