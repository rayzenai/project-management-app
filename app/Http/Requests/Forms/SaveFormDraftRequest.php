<?php

namespace App\Http\Requests\Forms;

/** The builder's save: the whole draft. */
class SaveFormDraftRequest extends EditFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['draft' => ['required', 'array']];
    }
}
