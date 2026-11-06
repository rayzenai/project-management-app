<?php

namespace App\Support\Documents;

use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A version's file for a person, streamed from the `documents` disk, named
 * after the document (an older version says which it is).
 */
final class DocumentDownload
{
    public static function of(Document $document, DocumentVersion $version): StreamedResponse
    {
        $name = $version->version === $document->version
            ? $document->fileName()
            : preg_replace('/\.'.preg_quote($document->file_type, '/').'$/', " (version {$version->version}).{$document->file_type}", $document->fileName());

        return Storage::disk((string) config('documents.disk'))->download($version->path, (string) $name, [
            'Content-Type' => DocumentTypes::mime($document->file_type),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
