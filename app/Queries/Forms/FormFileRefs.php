<?php

namespace App\Queries\Forms;

use App\Models\FormFile;
use App\Support\Forms\FormSchema;

/**
 * The files a page shows: a block's pictures and downloads, and the files in
 * someone's answers, by id, with the link that opens them
 * (`/workspace/forms/files/{id}`, which checks who may).
 */
final class FormFileRefs
{
    /** @return array{id: int, name: string, size: int, image: bool, url: string} */
    public static function of(FormFile $file): array
    {
        return ['id' => $file->id, 'name' => $file->name, 'size' => $file->size, 'image' => $file->isImage(), 'url' => "/workspace/forms/files/{$file->id}"];
    }

    /**
     * The content files the schema's blocks use.
     *
     * @param  array<string, mixed>  $schema
     * @return array<int, array{id: int, name: string, size: int, image: bool, url: string}>
     */
    public static function content(int $formId, array $schema): array
    {
        return self::byIds($formId, FormSchema::contentFileIds($schema), FormFile::PURPOSE_CONTENT);
    }

    /**
     * The answer files among these ids that are this respondent's: uploaded
     * under their key, or already their response's. Any other id (someone
     * else's, put into a draft by hand) says nothing about its file.
     *
     * @param  list<int>  $ids
     * @return array<int, array{id: int, name: string, size: int, image: bool, url: string}>
     */
    public static function own(int $formId, array $ids, string $respondentKey, ?int $responseId): array
    {
        if ($ids === []) {
            return [];
        }

        return FormFile::query()->where('form_id', $formId)->where('purpose', FormFile::PURPOSE_ANSWER)->whereIn('id', $ids)
            ->where(fn ($q) => $q->where('respondent_key', $respondentKey)->when($responseId !== null, fn ($r) => $r->orWhere('form_response_id', $responseId)))
            ->get()
            ->mapWithKeys(fn (FormFile $file): array => [$file->id => self::of($file)])
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{id: int, name: string, size: int, image: bool, url: string}>
     */
    public static function byIds(int $formId, array $ids, string $purpose): array
    {
        if ($ids === []) {
            return [];
        }

        return FormFile::query()->where('form_id', $formId)->where('purpose', $purpose)->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (FormFile $file): array => [$file->id => self::of($file)])
            ->all();
    }
}
