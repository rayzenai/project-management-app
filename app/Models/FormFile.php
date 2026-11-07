<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file on a form: one the builder put in a block (`purpose` `content`:
 * an image, a circular to download) or one a respondent added to a
 * question (`answer`, theirs by `respondent_key`, the response's once
 * submitted). Stored on the `documents` disk under `forms/`, never
 * overwritten or deleted.
 *
 * @property int $id
 * @property int $form_id
 * @property string $purpose
 * @property string $block_id
 * @property string|null $respondent_key
 * @property int|null $form_response_id
 * @property string $path
 * @property string $name
 * @property int $size
 * @property string $mime
 * @property int|null $uploaded_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Form $form
 * @property-read FormResponse|null $response
 */
class FormFile extends Model
{
    public const PURPOSE_CONTENT = 'content';

    public const PURPOSE_ANSWER = 'answer';

    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer', 'form_response_id' => 'integer'];
    }

    /**
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class)->withTrashed();
    }

    /**
     * @return BelongsTo<FormResponse, $this>
     */
    public function response(): BelongsTo
    {
        return $this->belongsTo(FormResponse::class, 'form_response_id');
    }

    /** Whether the browser may show it in the page (a picture) rather than download it. */
    public function isImage(): bool
    {
        return in_array($this->mime, ['image/jpeg', 'image/png', 'image/webp'], true);
    }
}
