<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use App\Support\Documents\DocumentTypes;
use Illuminate\Validation\Rule;

/** A blank document, spreadsheet or presentation. */
class StoreDocumentRequest extends CreateDocumentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'title' => ['required', 'string', 'max:'.Document::TITLE_MAX],
            'type' => ['required', 'string', Rule::in(DocumentTypes::all())],
        ];
    }
}
