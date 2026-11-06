<?php

namespace App\Support\Documents;

use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writes a version's file to the `documents` disk from a local file, at the
 * version's own path. Never overwrites: each version has a new path. The
 * disk throws on failure, so a caller's transaction never keeps a row for a
 * file that was not written.
 */
final class DocumentFiles
{
    public static function write(Document $document, int $version, string $localFile): string
    {
        $path = DocumentVersion::pathFor($document, $version);
        $stream = fopen($localFile, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Couldn't read the file for document {$document->id}.");
        }

        try {
            Storage::disk((string) config('documents.disk'))->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }

        return $path;
    }
}
