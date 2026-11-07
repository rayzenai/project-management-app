<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormFile;
use App\Support\ServiceResult;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores a file on a form: a block's picture or file (content) or a
 * respondent's file for a question (answer, theirs by respondent key until
 * their response takes it). Written to the documents disk under
 * `forms/{form}/`, at a new path each time, never overwritten or deleted.
 * The requests have checked who may add it, its kind and its size.
 */
class FormFileService
{
    public function store(Form $form, string $purpose, string $blockId, ?string $respondentKey, UploadedFile $upload, Authenticatable $user): ServiceResult
    {
        $extension = strtolower($upload->getClientOriginalExtension());
        $path = "forms/{$form->id}/{$purpose}/".Str::uuid()->toString().($extension === '' ? '' : ".{$extension}");

        try {
            $stream = fopen((string) $upload->getRealPath(), 'rb');

            if ($stream === false) {
                return ServiceResult::failure("Couldn't read the file. Try again.", 422, ['file' => "Couldn't read the file. Try again."]);
            }

            try {
                Storage::disk((string) config('documents.disk'))->writeStream($path, $stream);
            } finally {
                fclose($stream);
            }

            $file = new FormFile;
            $file->form_id = $form->id;
            $file->purpose = $purpose;
            $file->block_id = $blockId;
            $file->respondent_key = $respondentKey;
            $file->path = $path;
            $file->name = mb_substr($upload->getClientOriginalName(), 0, 255) ?: 'file';
            $file->size = (int) $upload->getSize();
            $file->mime = mb_substr((string) ($upload->getMimeType() ?: 'application/octet-stream'), 0, 120);
            $file->uploaded_by = (int) $user->getAuthIdentifier();
            $file->save();
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::failure("Couldn't save the file. Try again.", 500, ['file' => "Couldn't save the file. Try again."]);
        }

        return ServiceResult::success($file, 'Added '.$file->name.'.');
    }
}
