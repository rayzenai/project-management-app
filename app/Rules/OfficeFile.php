<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * The upload is the Word, Excel or PowerPoint file its extension says: a
 * readable OOXML package with that format's main part. A renamed file, a
 * password-protected one (not a zip at all) or an older .doc fails here
 * rather than in the editor.
 */
class OfficeFile implements ValidationRule
{
    /** The part each format cannot be without. */
    private const MAIN_PARTS = [
        'docx' => 'word/document.xml',
        'xlsx' => 'xl/workbook.xml',
        'pptx' => 'ppt/presentation.xml',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $message = __('This file could not be read as a Word, Excel or PowerPoint file.');

        if (! $value instanceof UploadedFile) {
            $fail($message);

            return;
        }

        $mainPart = self::MAIN_PARTS[strtolower($value->getClientOriginalExtension())] ?? null;
        $zip = new ZipArchive;

        if ($mainPart === null || $zip->open($value->getRealPath(), ZipArchive::RDONLY) !== true) {
            $fail($message);

            return;
        }

        $ok = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($mainPart) !== false;
        $zip->close();

        if (! $ok) {
            $fail($message);
        }
    }
}
