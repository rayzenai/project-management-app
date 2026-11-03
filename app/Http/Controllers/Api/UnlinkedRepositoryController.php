<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\CreateProjectForRepositoryRequest;
use App\Http\Resources\GitRepositoryResource;
use App\Http\Resources\ProjectResource;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Project;
use App\Services\Workspace\CreateProjectForRepositoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\UnlinkedRepositoryController.
 */
class UnlinkedRepositoryController extends Controller
{
    use RespondsWithServiceResult;

    public function createProject(CreateProjectForRepositoryRequest $request, GitConnection $connection, string $forgeId, CreateProjectForRepositoryService $service): JsonResponse
    {
        $result = $service->execute($connection, (int) $forgeId, $request->projectAttributes(), $request->user());
        $data = is_array($result->data) ? $result->data : [];

        return $this->respondWithResult(
            $result,
            $result->success && ($data['project'] ?? null) instanceof Project && ($data['repository'] ?? null) instanceof GitRepository
                ? ['project' => new ProjectResource($data['project']->load('program')), 'repository' => new GitRepositoryResource($data['repository'])]
                : null,
            $result->success ? 201 : null,
        );
    }
}
