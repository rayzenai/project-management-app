<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\DestroyRepositoryGrantRequest;
use App\Http\Requests\ShowRepositoryAccessRequest;
use App\Http\Requests\StoreRepositoryGrantRequest;
use App\Http\Requests\UpdateRepositoryGrantRequest;
use App\Http\Resources\GitRepositoryGrantResource;
use App\Models\GitRepository;
use App\Models\GitRepositoryGrant;
use App\Models\Member;
use App\Models\Project;
use App\Queries\RepositoryAccessCandidatesQuery;
use App\Queries\RepositoryAccessQuery;
use App\Services\Workspace\GrantRepositoryAccessService;
use App\Services\Workspace\RevokeRepositoryAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\RepositoryGrantController.
 */
class RepositoryGrantController extends Controller
{
    use RespondsWithServiceResult;

    public function index(ShowRepositoryAccessRequest $request, Project $project, GitRepository $repository, RepositoryAccessQuery $query): JsonResponse
    {
        return response()->json([
            'message' => 'Repository access retrieved.',
            'data' => $query->get($repository),
        ]);
    }

    public function candidates(ShowRepositoryAccessRequest $request, Project $project, GitRepository $repository, RepositoryAccessCandidatesQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get($request->user(), $repository, (string) $request->validated('q'))]);
    }

    public function store(StoreRepositoryGrantRequest $request, Project $project, GitRepository $repository, GrantRepositoryAccessService $service): JsonResponse
    {
        $member = Member::query()->findOrFail($request->integer('member_id'));
        $result = $service->execute($repository, $member, (string) $request->validated('permission'), $request->user());

        return $this->respondWithResult(
            $result,
            $result->data instanceof GitRepositoryGrant ? new GitRepositoryGrantResource($result->data) : null,
            $result->success ? 201 : null,
        );
    }

    public function update(UpdateRepositoryGrantRequest $request, Project $project, GitRepository $repository, GitRepositoryGrant $grant, GrantRepositoryAccessService $service): JsonResponse
    {
        $result = $service->execute($repository, $grant->member, (string) $request->validated('permission'), $request->user());

        return $this->respondWithResult(
            $result,
            $result->data instanceof GitRepositoryGrant ? new GitRepositoryGrantResource($result->data) : null,
        );
    }

    public function destroy(DestroyRepositoryGrantRequest $request, Project $project, GitRepository $repository, GitRepositoryGrant $grant, RevokeRepositoryAccessService $service): JsonResponse
    {
        return $this->respondWithResult($service->execute($grant));
    }
}
