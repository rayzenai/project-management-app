<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Requests\MyWorkRequest;
use App\Models\Form;
use App\Queries\Forms\FormItems;
use App\Queries\Forms\FormLibraryQuery;
use App\Queries\MyWorkQuery;
use App\Support\WorkspaceOrganization;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My work: one list of the signed-in person's own work (MyWorkQuery), kept
 * to a super-admin's switched organization.
 */
class MyWorkController extends Controller
{
    public function __invoke(MyWorkRequest $request, MyWorkQuery $query, FormLibraryQuery $forms): Response
    {
        return Inertia::render('MyWork', [
            ...$query->get($request->user(), WorkspaceOrganization::chosen($request->user()), $request->boolean('done')),
            // Forms waiting for the person, so a data call doesn't depend on someone keeping its link.
            'forms_to_fill' => $forms->toFill($request->user())->map(fn (Form $form): array => FormItems::of($form, $request->user()))->values()->all(),
        ]);
    }
}
