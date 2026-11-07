<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\Forms\EditFormRequest;
use App\Http\Requests\Forms\FormShareRequest;
use App\Http\Requests\Forms\UpdateFormRespondentsRequest;
use App\Http\Requests\Forms\UpdateFormTasksRequest;
use App\Models\Form;
use App\Models\FormShare;
use App\Models\Project;
use App\Queries\Forms\FormBuilderQuery;
use App\Queries\Forms\FormCandidatesQuery;
use App\Queries\Forms\FormSharingQuery;
use App\Services\Forms\FormRespondentsService;
use App\Services\Forms\FormSharingService;
use App\Services\Forms\FormTaskSettingsService;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The people around a form, in the builder (Can edit): who fills it in,
 * whom it is shared with, and whom its tasks go to. JSON, for the builder's
 * panels.
 */
class FormPeopleController extends Controller
{
    use RespondsWithServiceResult;

    public function respondents(UpdateFormRespondentsRequest $request, FormRespondentsService $service, FormBuilderQuery $query): JsonResponse
    {
        $result = $service->execute($request->form(), (string) $request->validated('respondents'), $request->memberIds(), $request->organizationIds());

        return $this->respondWithResult($result, $result->success ? $query->for($request->form()->refresh(), $request->user()) : null);
    }

    public function people(EditFormRequest $request, FormCandidatesQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->people($request->form(), (string) $request->query('q', ''), $request->boolean('unshared'))]);
    }

    public function sharing(EditFormRequest $request, FormSharingQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->for($request->form())]);
    }

    public function share(FormShareRequest $request, FormSharingService $service): JsonResponse
    {
        $result = $service->share($request->form(), $request->person(), (string) $request->validated('permission'), $request->user());

        return $this->respondWithResult($result, $result->data instanceof FormShare ? FormSharingQuery::share($result->data) : null, 201);
    }

    public function changeShare(FormShareRequest $request, Form $form, FormShare $share, FormSharingService $service): JsonResponse
    {
        abort_unless($share->form_id === $form->id, 404);
        $result = $service->changePermission($share, (string) $request->validated('permission'));

        return $this->respondWithResult($result, $result->data instanceof FormShare ? FormSharingQuery::share($result->data) : null);
    }

    public function unshare(EditFormRequest $request, Form $form, FormShare $share, FormSharingService $service): JsonResponse
    {
        abort_unless($share->form_id === $form->id, 404);

        return $this->respondWithResult($service->unshare($share));
    }

    public function tasks(UpdateFormTasksRequest $request, FormTaskSettingsService $service, FormBuilderQuery $query): JsonResponse
    {
        $result = $service->execute($request->form(), $request->tasks());

        return $this->respondWithResult($result, $result->success ? $query->for($request->form()->refresh(), $request->user()) : null);
    }

    public function assignees(EditFormRequest $request, FormCandidatesQuery $query): JsonResponse
    {
        $project = Project::query()->where('slug', (string) $request->query('project', ''))->first();
        abort_unless($project !== null && WorkspaceAccess::canSendFormTasksTo($request->user(), $request->form(), $project), 404);

        return response()->json(['data' => $query->assignees($project, (string) $request->query('q', ''))]);
    }
}
