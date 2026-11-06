<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Support\Documents\DocumentFiles;
use App\Support\Documents\DocumentTypes;
use App\Support\ServiceResult;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A new document, in a project or (no project) personal, its first version
 * written to the `documents` disk: blank from the type's template, or an
 * uploaded file (already checked by the request's OfficeFile rule). The row, the file and the version are
 * one step: a failed write leaves no row.
 */
class CreateDocumentService
{
    public function blank(?Project $project, string $title, string $type, Authenticatable $user): ServiceResult
    {
        return $this->create($project, $title, $type, DocumentTypes::blankPath($type), DocumentVersion::SOURCE_CREATED, $user);
    }

    public function upload(?Project $project, UploadedFile $file, ?string $title, Authenticatable $user): ServiceResult
    {
        $type = strtolower($file->getClientOriginalExtension());
        $title = trim((string) $title) !== ''
            ? (string) $title
            : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        return $this->create($project, $title, $type, (string) $file->getRealPath(), DocumentVersion::SOURCE_UPLOADED, $user);
    }

    private function create(?Project $project, string $title, string $type, string $source, string $kind, Authenticatable $user): ServiceResult
    {
        $title = mb_substr(trim($title), 0, Document::TITLE_MAX) ?: 'Untitled';
        $userId = (int) $user->getAuthIdentifier();

        try {
            $document = DB::transaction(function () use ($project, $title, $type, $source, $kind, $userId): Document {
                $document = new Document(['project_id' => $project?->id, 'title' => $title, 'file_type' => $type]);
                // No project: a personal document, its maker's.
                $document->owner_id = $project === null ? $userId : null;
                $document->version = 1;
                $document->editor_key = Document::newEditorKey();
                $document->size = (int) filesize($source);
                $document->created_by = $userId;
                $document->updated_by = $userId;
                $document->save();

                $path = DocumentFiles::write($document, 1, $source);

                DocumentVersion::query()->create([
                    'document_id' => $document->id,
                    'version' => 1,
                    'path' => $path,
                    'size' => $document->size,
                    'sha256' => (string) hash_file('sha256', $source),
                    'user_id' => $userId,
                    'source' => $kind,
                ]);

                return $document;
            });
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::failure("Couldn't save {$title}. Try again.", 500);
        }

        $verb = $kind === DocumentVersion::SOURCE_UPLOADED ? 'Uploaded' : 'Created';

        return ServiceResult::success($document, "{$verb} {$document->title}.");
    }
}
