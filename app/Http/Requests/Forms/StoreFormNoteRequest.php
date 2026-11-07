<?php

namespace App\Http\Requests\Forms;

/** A note on a response, for the form's side only. */
class StoreFormNoteRequest extends FormResponsesRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['text' => ['required', 'string', 'max:2000']];
    }
}
