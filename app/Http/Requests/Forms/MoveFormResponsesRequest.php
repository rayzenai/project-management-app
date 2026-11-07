<?php

namespace App\Http\Requests\Forms;

/** Move responses to a stage, optionally with a note. */
class MoveFormResponsesRequest extends FormResponsesRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'stage' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->validated('ids'))));
    }
}
