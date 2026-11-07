<?php

namespace App\Http\Requests\Forms;

use App\Support\WorkspaceAccess;

/**
 * Save a draft, clear it or Submit: someone filling the form in while it is
 * open ({@see WorkspaceAccess::canAnswerForm()}), or, with `test`, the
 * form's side trying it (Can edit).
 */
class FillFormRequest extends FormRouteRequest
{
    public function authorize(): bool
    {
        if (WorkspaceAccess::memberFor($this->user()) === null) {
            return false;
        }

        return $this->isTest()
            ? WorkspaceAccess::canEditForm($this->user(), $this->form())
            : WorkspaceAccess::canAnswerForm($this->user(), $this->form());
    }

    public function isTest(): bool
    {
        return $this->boolean('test');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answers' => [$this->isMethod('delete') ? 'nullable' : 'present', 'array'],
            'test' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function answers(): array
    {
        $answers = $this->input('answers', []);

        return is_array($answers) ? $answers : [];
    }
}
