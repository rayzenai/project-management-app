<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Forms\DuplicateFormRequest;
use App\Http\Requests\Forms\EditFormRequest;
use App\Http\Requests\Forms\SaveFormDraftRequest;
use App\Http\Requests\Forms\UpdateFormSettingsRequest;
use App\Http\Requests\Forms\UploadFormFileRequest;
use App\Models\Form;
use App\Models\FormFile;
use App\Queries\Forms\FormBuilderQuery;
use App\Queries\Forms\FormFileRefs;
use App\Services\Forms\CloseFormService;
use App\Services\Forms\DeleteFormService;
use App\Services\Forms\DuplicateFormService;
use App\Services\Forms\FormFileService;
use App\Services\Forms\PublishFormService;
use App\Services\Forms\SaveFormDraftService;
use App\Services\Forms\UpdateFormSettingsService;
use App\Support\ServiceResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The builder (Can edit): its page, saving the draft as people work,
 * settings, Publish, Close and Reopen, a copy, deleting an unanswered form
 * and the blocks' files. Its changes answer in JSON, as the page keeps its
 * own state; making a copy and deleting go to another page.
 */
class FormBuilderController extends Controller
{
    use RedirectsWithServiceResult;
    use RespondsWithServiceResult;

    public function edit(EditFormRequest $request, FormBuilderQuery $query): Response
    {
        return Inertia::render('Forms/Edit', $query->for($request->form(), $request->user()));
    }

    public function draft(SaveFormDraftRequest $request, SaveFormDraftService $service): JsonResponse
    {
        return $this->respondWithResult($service->execute($request->form(), $request->validated('draft')));
    }

    public function update(UpdateFormSettingsRequest $request, UpdateFormSettingsService $service, FormBuilderQuery $query): JsonResponse
    {
        return $this->withBuilder($service->execute($request->form(), $request->validated()), $query, $request);
    }

    public function publish(EditFormRequest $request, PublishFormService $service, FormBuilderQuery $query): JsonResponse
    {
        $form = $request->form();

        return $this->withBuilder($service->execute($form, $request->user(), $request->boolean('notify', (bool) $form->setting('notify', true))), $query, $request);
    }

    public function close(EditFormRequest $request, CloseFormService $service, FormBuilderQuery $query): JsonResponse
    {
        return $this->withBuilder($service->close($request->form()), $query, $request);
    }

    public function reopen(EditFormRequest $request, CloseFormService $service, FormBuilderQuery $query): JsonResponse
    {
        return $this->withBuilder($service->reopen($request->form()), $query, $request);
    }

    public function duplicate(DuplicateFormRequest $request, DuplicateFormService $service): RedirectResponse
    {
        $result = $service->execute($request->form(), $request->project(), $request->user());

        if ($result->success && $result->data instanceof Form) {
            return redirect()->route('workspace.forms.edit', ['form' => $result->data->id])
                ->with('workspace_flash', ['success' => true, 'message' => $result->message]);
        }

        return $this->redirectWithResult($result);
    }

    public function destroy(EditFormRequest $request, DeleteFormService $service): RedirectResponse
    {
        $result = $service->execute($request->form(), $request->user());

        if ($result->success) {
            return redirect()->route('workspace.forms.index')->with('workspace_flash', ['success' => true, 'message' => $result->message]);
        }

        return $this->redirectWithResult($result);
    }

    public function upload(UploadFormFileRequest $request, FormFileService $service): JsonResponse
    {
        $form = $request->form();
        $content = $request->isContent();
        $key = null;

        if (! $content) {
            $member = $request->member();
            $key = $request->boolean('test') ? 't:'.$member->id : $form->respondentKeyFor($member);
        }

        $result = $service->store($form, $content ? FormFile::PURPOSE_CONTENT : FormFile::PURPOSE_ANSWER, (string) $request->validated('block'), $key, $request->file('file'), $request->user());

        return $this->respondWithResult($result, $result->data instanceof FormFile ? FormFileRefs::of($result->data) : null, 201);
    }

    /** A change's answer carries the builder's state again, so the page shows what was saved. */
    private function withBuilder(ServiceResult $result, FormBuilderQuery $query, EditFormRequest $request): JsonResponse
    {
        return $this->respondWithResult($result, $result->success ? $query->for($request->form()->refresh(), $request->user()) : null);
    }
}
