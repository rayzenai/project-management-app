<?php

namespace App\Http\Requests\Forms;

use App\Models\FormFile;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/** Open one of a form's files ({@see WorkspaceAccess::canOpenFormFile()}). */
class OpenFormFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $file = $this->route('file');

        return $file instanceof FormFile && WorkspaceAccess::canOpenFormFile($this->user(), $file);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /** A missing file and one the person may not open answer alike. */
    protected function failedAuthorization(): void
    {
        abort(404);
    }
}
