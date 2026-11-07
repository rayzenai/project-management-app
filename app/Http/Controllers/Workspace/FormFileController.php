<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Requests\Forms\OpenFormFileRequest;
use App\Models\FormFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One of a form's files, for whoever may open it: a picture shows in the
 * page, anything else downloads under its own name.
 */
class FormFileController extends Controller
{
    public function __invoke(OpenFormFileRequest $request, FormFile $file): StreamedResponse
    {
        $disk = Storage::disk((string) config('documents.disk'));
        $headers = ['Content-Type' => $file->mime, 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff'];

        return $file->isImage()
            ? $disk->response($file->path, $file->name, $headers, 'inline')
            : $disk->download($file->path, $file->name, $headers);
    }
}
