<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Requests\Structure\OrganogramRequest;
use App\Queries\Structure\OrganizationChoicesQuery;
use App\Queries\Structure\OrganogramQuery;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organogram page: the viewer's organization (a super-admin picks one)
 * as an outline to rearrange and a chart to print, with its designations.
 */
class OrganogramController extends Controller
{
    public function index(OrganogramRequest $request, OrganogramQuery $query, OrganizationChoicesQuery $organizations): Response
    {
        $organizationId = $request->organizationId();

        return Inertia::render('Organogram/Index', [
            'organogram' => $organizationId === null ? null : $query->get($request->user(), $organizationId),
            'organizations' => $organizations->options($request->user()),
        ]);
    }
}
