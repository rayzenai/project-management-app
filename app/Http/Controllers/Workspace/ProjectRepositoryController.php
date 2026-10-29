<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\LinkRepositoryRequest;
use App\Http\Requests\SearchRepositoryCandidatesRequest;
use App\Http\Requests\UnlinkRepositoryRequest;
use App\Models\GitRepository;
use App\Models\Project;
use App\Queries\RepositoryCandidatesQuery;
use App\Services\Git\ForgejoException;
use App\Services\Workspace\LinkRepositoryService;
use App\Services\Workspace\UnlinkRepositoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * A project's linked forge repositories, from its Code tab: search the
 * forge organization's repositories, link one, unlink one. The manager, the
 * office admin or a super-admin, and only in an organization with git.
 */
class ProjectRepositoryController extends Controller
{
    use RedirectsWithServiceResult;

    public function candidates(SearchRepositoryCandidatesRequest $request, Project $project, RepositoryCandidatesQuery $query): JsonResponse
    {
        try {
            return response()->json(['data' => $query->get($project, (string) $request->validated('q'))]);
        } catch (ForgejoException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['__global' => $e->getMessage()]], 502);
        }
    }

    public function store(LinkRepositoryRequest $request, Project $project, LinkRepositoryService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($project, (string) $request->validated('full_name'), $request->user()));
    }

    public function destroy(UnlinkRepositoryRequest $request, Project $project, GitRepository $repository, UnlinkRepositoryService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($repository));
    }
}
