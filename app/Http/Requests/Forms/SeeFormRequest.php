<?php

namespace App\Http\Requests\Forms;

use App\Support\WorkspaceAccess;

/** See a form's questions: a respondent, or whoever may see its responses ({@see WorkspaceAccess::canSeeForm()}). */
class SeeFormRequest extends FormRouteRequest
{
    public function authorize(): bool
    {
        return WorkspaceAccess::canSeeForm($this->user(), $this->form());
    }
}
