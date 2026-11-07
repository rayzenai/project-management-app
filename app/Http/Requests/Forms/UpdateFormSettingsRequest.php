<?php

namespace App\Http\Requests\Forms;

use App\Models\Form;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A form's settings beside its questions. "Answering as" can't change once
 * a response is in: the responses would no longer fit it.
 */
class UpdateFormSettingsRequest extends EditFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:'.Form::TITLE_MAX],
            'opens_at' => ['sometimes', 'nullable', 'date'],
            'closes_at' => ['sometimes', 'nullable', 'date'],
            'response_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'answering' => ['sometimes', Rule::in([Form::ANSWERING_PERSON, Form::ANSWERING_ORGANIZATION])],
            'allow_edit' => ['sometimes', 'boolean'],
            'review_page' => ['sometimes', 'boolean'],
            'notify' => ['sometimes', 'boolean'],
            'stages' => ['sometimes', 'array', 'min:1', 'max:12'],
            'stages.*.id' => ['nullable', 'string', 'max:40'],
            'stages.*.name' => ['required', 'string', 'max:40'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $form = $this->form();

            if ($this->has('answering') && $this->input('answering') !== $form->answering && $form->responses()->exists()) {
                $validator->errors()->add('answering', 'Responses are in, so who answers can\'t change now.');
            }

            $opens = $this->has('opens_at') ? $this->input('opens_at') : $form->opens_at?->toIso8601String();
            $closes = $this->has('closes_at') ? $this->input('closes_at') : $form->closes_at?->toIso8601String();

            if (is_string($opens) && is_string($closes) && strtotime($closes) <= strtotime($opens)) {
                $validator->errors()->add('closes_at', 'Close it after it opens.');
            }
        });
    }
}
