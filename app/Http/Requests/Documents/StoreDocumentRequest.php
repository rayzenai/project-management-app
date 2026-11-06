<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use App\Support\Documents\DocumentTypes;
use Illuminate\Validation\Rule;

/** A new blank document, spreadsheet or presentation. */
class StoreDocumentRequest extends DocumentsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.Document::TITLE_MAX],
            'type' => ['required', 'string', Rule::in(DocumentTypes::all())],
        ];
    }
}
