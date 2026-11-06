<?php

namespace App\Models;

use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A project's Word, Excel or PowerPoint file, edited together in ONLYOFFICE
 * (docs/superpowers/specs/2026-11-05-documents-design.md). Every saved
 * version is a DocumentVersion with its own file; `version` is the current
 * one. Who may use it is {@see WorkspaceAccess::canUseProjectDocuments()}.
 * The trash is a mark, never purged: no file is ever deleted.
 *
 * @property int $id
 * @property int $project_id
 * @property string $title
 * @property string $file_type
 * @property int $version
 * @property string $editor_key
 * @property int $size
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Project $project
 * @property-read User|null $creator
 * @property-read User|null $updater
 */
class Document extends Model
{
    use SoftDeletes;

    /** Titles are kept to this many characters. */
    public const TITLE_MAX = 200;

    // version, editor_key, size and the people are set by the services only.
    protected $fillable = ['project_id', 'title', 'file_type'];

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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Newest first.
     *
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    /** A fresh id for the editor: everyone editing one version shares it. */
    public static function newEditorKey(): string
    {
        return Str::random(40);
    }

    /** The file name a download gets: the title and the type's extension. */
    public function fileName(): string
    {
        $base = trim((string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $this->title));

        return ($base === '' ? 'document' : $base).'.'.$this->file_type;
    }
}
