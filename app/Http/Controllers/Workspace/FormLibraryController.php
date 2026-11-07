<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Forms\CreateFormRequest;
use App\Models\Form;
use App\Queries\Forms\FormLibraryQuery;
use App\Services\Forms\CreateFormService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Forms page (To fill, Filled, Managing) and making a form
 * (docs/superpowers/specs/2026-11-07-forms-design.md).
 */
class FormLibraryController extends Controller
{
    use RedirectsWithServiceResult;

    public function index(Request $request, FormLibraryQuery $query): Response
    {
        abort_unless((bool) config('forms.enabled'), 404);

        return Inertia::render('Forms/Index', $query->for($request->user()));
    }

    public function store(CreateFormRequest $request, CreateFormService $service): RedirectResponse
    {
        $result = $service->execute($request->project(), (string) $request->validated('title'), (string) ($request->validated('template') ?? 'blank'), $request->user());

        if ($result->success && $result->data instanceof Form) {
            return redirect()->route('workspace.forms.edit', ['form' => $result->data->id])
                ->with('workspace_flash', ['success' => true, 'message' => $result->message]);
        }

        return $this->redirectWithResult($result);
    }
}
