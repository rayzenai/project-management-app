<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Support\ServiceResult;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Into the trash, never purged: the files and versions stay, and restoring
 * (RestoreWorkspaceModel) brings it back as it was. An editor still open on
 * it can still save; the save lands as a new version.
 */
class TrashDocumentService
{
    public function execute(Document $document, Authenticatable $user): ServiceResult
    {
        DB::transaction(function () use ($document, $user): void {
            $document->deleted_by = (int) $user->getAuthIdentifier();
            $document->saveQuietly();
            $document->delete();
        });

        return ServiceResult::success(null, __('Moved :title to the trash.', ['title' => $document->title]));
    }
}
