<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\CreateProjectForRepositoryRequest;
use App\Models\GitConnection;
use App\Services\Workspace\CreateProjectForRepositoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * A forge repository no project links, from Code activity's notice: "New
 * project for it" (linking it to an existing project is the Code tab's
 * route, ProjectRepositoryController::store).
 */
class UnlinkedRepositoryController extends Controller
{
    use RedirectsWithServiceResult;

    public function createProject(CreateProjectForRepositoryRequest $request, GitConnection $connection, string $forgeId, CreateProjectForRepositoryService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($connection, (int) $forgeId, $request->projectAttributes(), $request->user()));
    }
}
