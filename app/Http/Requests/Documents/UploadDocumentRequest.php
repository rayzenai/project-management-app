<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use App\Rules\OfficeFile;
use App\Support\Documents\DocumentTypes;
use App\Support\Locales;

/** An uploaded .docx, .xlsx or .pptx; the title defaults to its file name. */
class UploadDocumentRequest extends CreateDocumentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'file' => ['required', 'file', 'max:'.(int) config('documents.max_upload_kilobytes'), 'extensions:'.implode(',', DocumentTypes::all()), new OfficeFile],
            'title' => ['nullable', 'string', 'max:'.Document::TITLE_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.extensions' => __('Upload a .docx, .xlsx or .pptx file.'),
            'file.max' => __('Files can be at most :size MB.', ['size' => Locales::number(intdiv((int) config('documents.max_upload_kilobytes'), 1024))]),
        ];
    }
}
