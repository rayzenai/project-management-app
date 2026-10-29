<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\DisableGitRequest;
use App\Http\Requests\EnableGitRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Workspace\DisableGitService;
use App\Services\Workspace\EnableGitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Super-admin only: connect an organization to its forge organization.
 */
class OrganizationGitController extends Controller
{
    use RedirectsWithServiceResult;

    public function store(EnableGitRequest $request, Organization $organization, EnableGitService $service): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{base_url: string, owner: string, token: string} $input */
        $input = $request->validated();

        return $this->redirectWithResult($service->execute($organization, $actor, $input));
    }

    public function destroy(DisableGitRequest $request, Organization $organization, DisableGitService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($organization));
    }
}
