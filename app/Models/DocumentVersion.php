<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved state of a document, with its own file on the `documents` disk
 * (`path`), never overwritten or deleted. `source` says how it came:
 * created blank, uploaded, or saved from the editor.
 *
 * @property int $id
 * @property int $document_id
 * @property int $version
 * @property string $path
 * @property int $size
 * @property string $sha256
 * @property int|null $user_id
 * @property string $source
 * @property CarbonImmutable|null $created_at
 * @property-read Document $document
 * @property-read User|null $user
 */
class DocumentVersion extends Model
{
    public const SOURCE_CREATED = 'created';

    public const SOURCE_UPLOADED = 'uploaded';

    public const SOURCE_EDITED = 'edited';

    public const UPDATED_AT = null;

    protected $fillable = ['document_id', 'version', 'path', 'size', 'sha256', 'user_id', 'source'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Where a version's file lives on the disk. */
    public static function pathFor(Document $document, int $version): string
    {
        return "projects/{$document->project_id}/documents/{$document->id}/v{$version}.{$document->file_type}";
    }
}
