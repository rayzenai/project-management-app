<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Support\ServiceResult;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A new title. The file and its versions stay as they are; downloads take
 * the new name. The editor shows the old one until the document is opened
 * again.
 */
class RenameDocumentService
{
    public function execute(Document $document, string $title, Authenticatable $user): ServiceResult
    {
        $title = mb_substr(trim($title), 0, Document::TITLE_MAX);

        if ($title === '' || $title === $document->title) {
            return ServiceResult::success($document, 'Nothing to change.');
        }

        $document->title = $title;
        $document->updated_by = (int) $user->getAuthIdentifier();
        $document->save();

        return ServiceResult::success($document, "Renamed to {$title}.");
    }
}
