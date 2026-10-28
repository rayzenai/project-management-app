<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Queries\OrganizationIndexQuery;
use App\Services\Workspace\DeleteOrganizationService;
use App\Support\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin only: the offices and ministries using the workspace. Their
 * office admins are people, added from the Team page.
 */
class OrganizationController extends Controller
{
    use RedirectsWithServiceResult;

    public function index(Request $request, OrganizationIndexQuery $query): Response
    {
        abort_unless(WorkspaceAccess::canManageOrganizations($request->user()), 403);

        return Inertia::render('Organizations/Index', $query->data());
    }

    public function store(StoreOrganizationRequest $request): RedirectResponse
    {
        Organization::create($request->validated());

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Organization created.']);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): RedirectResponse
    {
        $organization->update($request->validated());

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Organization renamed.']);
    }

    public function destroy(Request $request, Organization $organization, DeleteOrganizationService $service): RedirectResponse
    {
        abort_unless(WorkspaceAccess::canManageOrganizations($request->user()), 403);

        return $this->redirectWithResult($service->execute($organization));
    }
}
