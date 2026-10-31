<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Structure\DeleteDesignationRequest;
use App\Http\Requests\Structure\StoreDesignationRequest;
use App\Http\Requests\Structure\UpdateDesignationRequest;
use App\Models\Designation;
use App\Models\Organization;
use App\Services\Structure\CreateDesignationService;
use App\Services\Structure\DeleteDesignationService;
use App\Services\Structure\UpdateDesignationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * An organization's designations, managed on the organogram page.
 */
class DesignationController extends Controller
{
    use RedirectsWithServiceResult;

    public function store(StoreDesignationRequest $request, Organization $organization, CreateDesignationService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($organization, $request->designation()));
    }

    public function update(UpdateDesignationRequest $request, Designation $designation, UpdateDesignationService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($designation, $request->changes()));
    }

    public function destroy(DeleteDesignationRequest $request, Designation $designation, DeleteDesignationService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($designation));
    }
}
