<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var array{display: list<string>, body: list<string>, mono: list<string>} $fontAllowList */
        $fontAllowList = config('themes.font_allow_list');

        return [
            'theme' => ['sometimes', Rule::in(array_keys(config('themes.themes')))],
            'font_override' => ['sometimes', 'nullable', 'array'],
            'font_override.display' => ['nullable', Rule::in($fontAllowList['display'])],
            'font_override.body' => ['nullable', Rule::in($fontAllowList['body'])],
            'font_override.mono' => ['nullable', Rule::in($fontAllowList['mono'])],
            'email_notifications' => ['sometimes', 'boolean'],
        ];
    }
}
