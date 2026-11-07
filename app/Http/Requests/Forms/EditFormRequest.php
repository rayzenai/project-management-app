<?php

namespace App\Http\Requests\Forms;

use App\Support\WorkspaceAccess;

/** Build the form: Can edit ({@see WorkspaceAccess::canEditForm()}). */
class EditFormRequest extends FormRouteRequest
{
    public function authorize(): bool
    {
        return WorkspaceAccess::canEditForm($this->user(), $this->form());
    }
}
