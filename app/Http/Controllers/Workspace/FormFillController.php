<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\Forms\EditFormRequest;
use App\Http\Requests\Forms\FillFormRequest;
use App\Models\Form;
use App\Models\FormResponse;
use App\Queries\Forms\FormFillQuery;
use App\Services\Forms\FormFillService;
use App\Support\Forms\FormCodes;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Filling a form in: its link (`/f/{code}`, after sign-in), the form's side
 * trying it (against the draft, nothing kept), and the draft, Clear and
 * Submit (JSON). Someone the form isn't for sees only that, with nothing
 * about the form.
 */
class FormFillController extends Controller
{
    use RespondsWithServiceResult;

    public function link(Request $request, string $code, FormFillQuery $query): Response
    {
        abort_unless((bool) config('forms.enabled'), 404);
        $form = Form::query()->where('code', FormCodes::normalize($code))->first();

        if ($form === null || ! WorkspaceAccess::canSeeForm($request->user(), $form)) {
            return Inertia::render('Forms/Fill', ['state' => 'not_for_you', 'form' => null]);
        }

        return Inertia::render('Forms/Fill', $query->for($form, $request->user(), false));
    }

    public function try(EditFormRequest $request, FormFillQuery $query): Response
    {
        abort_unless(WorkspaceAccess::memberFor($request->user()) !== null, 403, 'Only someone with a person record can try a form.');

        return Inertia::render('Forms/Fill', $query->for($request->form(), $request->user(), true));
    }

    public function draft(FillFormRequest $request, FormFillService $service): JsonResponse
    {
        return $this->respondWithResult($service->saveDraft($request->form(), $request->member(), $request->answers(), $request->isTest(), $request->user()));
    }

    public function clear(FillFormRequest $request, FormFillService $service): JsonResponse
    {
        return $this->respondWithResult($service->clearDraft($request->form(), $request->member(), $request->isTest()));
    }

    public function submit(FillFormRequest $request, FormFillService $service): JsonResponse
    {
        $result = $service->submit($request->form(), $request->member(), $request->answers(), $request->isTest(), $request->user());

        return $this->respondWithResult($result, $result->data instanceof FormResponse
            ? ['reference' => $result->data->reference, 'submitted_at' => $result->data->submitted_at->toIso8601String(), 'test' => false]
            : null);
    }
}
