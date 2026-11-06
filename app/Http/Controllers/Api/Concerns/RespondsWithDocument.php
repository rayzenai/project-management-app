<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Support\ServiceResult;
use Illuminate\Http\JsonResponse;

/**
 * A service's answer about one document, with the document as the viewer
 * sees it (DocumentResource).
 */
trait RespondsWithDocument
{
    use RespondsWithServiceResult;

    private function respondWithDocument(ServiceResult $result, ?int $status = null): JsonResponse
    {
        $document = $result->data;

        if (! $result->success || ! $document instanceof Document) {
            return $this->respondWithResult($result);
        }

        $payload = (new DocumentResource($document->loadMissing(['creator:id,name', 'updater:id,name', 'owner:id,name', 'project.program'])))
            ->withViewer(request()->user())
            ->resolve();

        return $this->respondWithResult($result, $payload, $status);
    }
}
