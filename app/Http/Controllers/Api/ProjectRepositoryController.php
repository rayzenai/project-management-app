<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\LinkRepositoryRequest;
use App\Http\Requests\SearchRepositoryCandidatesRequest;
use App\Http\Requests\UnlinkRepositoryRequest;
use App\Http\Resources\GitRepositoryResource;
use App\Models\GitRepository;
use App\Models\Project;
use App\Queries\RepositoryCandidatesQuery;
use App\Services\Git\ForgejoException;
use App\Services\Workspace\LinkRepositoryService;
use App\Services\Workspace\UnlinkRepositoryService;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\ProjectRepositoryController, plus the list of a
 * project's repositories for anyone who can view the project's code
 * ({@see WorkspaceAccess::canViewProjectCode()}).
 */
class ProjectRepositoryController extends Controller
{
    use RespondsWithServiceResult;

    public function index(Request $request, Project $project): JsonResponse
    {
        abort_unless(WorkspaceAccess::canViewProjectCode($request->user(), $project), 403);

        return response()->json([
            'message' => 'Repositories retrieved.',
            'data' => GitRepositoryResource::collection($project->repositories()->orderBy('full_name')->get()),
        ]);
    }

    public function candidates(SearchRepositoryCandidatesRequest $request, Project $project, RepositoryCandidatesQuery $query): JsonResponse
    {
        try {
            return response()->json(['data' => $query->get($project, (string) $request->validated('q'))]);
        } catch (ForgejoException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['__global' => $e->getMessage()]], 502);
        }
    }

    public function store(LinkRepositoryRequest $request, Project $project, LinkRepositoryService $service): JsonResponse
    {
        $result = $service->execute($project, (string) $request->validated('full_name'), $request->user());

        return $this->respondWithResult(
            $result,
            $result->data instanceof GitRepository ? new GitRepositoryResource($result->data) : null,
            $result->success ? 201 : null,
        );
    }

    public function destroy(UnlinkRepositoryRequest $request, Project $project, GitRepository $repository, UnlinkRepositoryService $service): JsonResponse
    {
        return $this->respondWithResult($service->execute($repository));
    }
}
