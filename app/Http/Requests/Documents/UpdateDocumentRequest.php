<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;

/** A new title. */
class UpdateDocumentRequest extends DocumentsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.Document::TITLE_MAX],
        ];
    }
}
