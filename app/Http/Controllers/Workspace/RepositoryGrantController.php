<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\DestroyRepositoryGrantRequest;
use App\Http\Requests\ShowRepositoryAccessRequest;
use App\Http\Requests\StoreRepositoryGrantRequest;
use App\Http\Requests\UpdateRepositoryGrantRequest;
use App\Models\GitRepository;
use App\Models\GitRepositoryGrant;
use App\Models\Member;
use App\Models\Project;
use App\Queries\RepositoryAccessCandidatesQuery;
use App\Queries\RepositoryAccessQuery;
use App\Services\Workspace\GrantRepositoryAccessService;
use App\Services\Workspace\RevokeRepositoryAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Who may pull and push a linked repository on the forge, from the Code
 * tab: see its maintainers and grants, search people to add, give, change
 * and take away access. Whoever may link the project's repositories.
 */
class RepositoryGrantController extends Controller
{
    use RedirectsWithServiceResult;

    public function index(ShowRepositoryAccessRequest $request, Project $project, GitRepository $repository, RepositoryAccessQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get($repository)]);
    }

    public function candidates(ShowRepositoryAccessRequest $request, Project $project, GitRepository $repository, RepositoryAccessCandidatesQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get($request->user(), $repository, (string) $request->validated('q'))]);
    }

    public function store(StoreRepositoryGrantRequest $request, Project $project, GitRepository $repository, GrantRepositoryAccessService $service): RedirectResponse
    {
        $member = Member::query()->findOrFail($request->integer('member_id'));

        return $this->redirectWithResult($service->execute($repository, $member, (string) $request->validated('permission'), $request->user()));
    }

    public function update(UpdateRepositoryGrantRequest $request, Project $project, GitRepository $repository, GitRepositoryGrant $grant, GrantRepositoryAccessService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($repository, $grant->member, (string) $request->validated('permission'), $request->user()));
    }

    public function destroy(DestroyRepositoryGrantRequest $request, Project $project, GitRepository $repository, GitRepositoryGrant $grant, RevokeRepositoryAccessService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($grant));
    }
}
