<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use Illuminate\Validation\Rule;

/** Change one person's share: Can edit or Can view. */
class UpdateDocumentShareRequest extends ShareDocumentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permission' => ['required', Rule::in(Document::ACCESSES)],
        ];
    }
}
