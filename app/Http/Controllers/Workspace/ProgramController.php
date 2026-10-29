<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\StoreProgramRequest;
use App\Http\Requests\UpdateProgramRequest;
use App\Models\Program;
use App\Services\Workspace\CreateProgramService;
use App\Services\Workspace\DeleteProgramService;
use App\Services\Workspace\UpdateProgramService;
use App\Support\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProgramController extends Controller
{
    use RedirectsWithServiceResult;

    public function store(StoreProgramRequest $request, CreateProgramService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($request->validated()));
    }

    public function update(UpdateProgramRequest $request, Program $program, UpdateProgramService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($program, $request->validated()));
    }

    public function destroy(Request $request, Program $program, DeleteProgramService $service): RedirectResponse
    {
        abort_unless(WorkspaceAccess::canManagePrograms($request->user(), $program->organization_id), 403);

        return $this->redirectWithResult($service->execute($program));
    }
}
