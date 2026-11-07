<?php

namespace App\Http\Requests\Forms;

use App\Models\Form;
use App\Support\WorkspaceAccess;

/** Copy a form the person may edit into a project they may make forms in. */
class DuplicateFormRequest extends CreateFormRequest
{
    public function authorize(): bool
    {
        $form = $this->route('form');

        return $form instanceof Form && WorkspaceAccess::canEditForm($this->user(), $form);
    }

    public function form(): Form
    {
        $form = $this->route('form');
        abort_unless($form instanceof Form, 404);

        return $form;
    }
}
