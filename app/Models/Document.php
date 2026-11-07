<?php

namespace App\Models;

use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A Word, Excel or PowerPoint file, edited together in ONLYOFFICE
 * (docs/superpowers/specs/2026-11-05-documents-design.md and
 * 2026-11-06-documents-workspace-design.md). It belongs to a project
 * (`project_id`) or to the person who made it (`owner_id`, a personal
 * document); `organization_access` opens it to everyone in its organization
 * and `shares` give it to chosen people. Who may do what is
 * {@see WorkspaceAccess::documentAccess()}. Every saved version is a
 * DocumentVersion with its own file; `version` is the current one. The
 * trash is a mark, never purged: no file is ever deleted.
 *
 * @property int $id
 * @property int|null $project_id
 * @property int|null $owner_id
 * @property string|null $organization_access
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
 * @property-read Project|null $project
 * @property-read User|null $owner
 * @property-read User|null $creator
 * @property-read User|null $updater
 */
class Document extends Model
{
    use SoftDeletes;

    /** Titles are kept to this many characters. */
    public const TITLE_MAX = 200;

    /** Access levels, also a share's permission and organization_access. */
    public const ACCESS_EDIT = 'edit';

    public const ACCESS_VIEW = 'view';

    public const ACCESSES = [self::ACCESS_VIEW, self::ACCESS_EDIT];

    /** Why a person who owns personal documents is never deleted. */
    public const OWNER_CANNOT_BE_DELETED = 'They own personal documents; deactivate them instead.';

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
     * A personal document's owner; null for a project's.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<DocumentShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(DocumentShare::class);
    }

    /** In no project: it belongs to its owner. */
    public function isPersonal(): bool
    {
        return $this->project_id === null;
    }

    /**
     * Whether the person owns a personal document, trashed ones included: then
     * their login may not be deleted (they are deactivated instead).
     */
    public static function hasPersonalDocuments(User $user): bool
    {
        return static::withTrashed()->where('owner_id', $user->id)->exists();
    }

    /** OWNER_CANNOT_BE_DELETED in the reader's language, for wherever it is shown. */
    public static function ownerCannotBeDeleted(): string
    {
        return __('They own personal documents; deactivate them instead.');
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

    /**
     * The documents the user may open: exactly those where
     * WorkspaceAccess::documentAccess is not null (its twin; keep them in
     * step). Trashed ones too when the caller asks for them.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): Builder
    {
        if (! config('documents.enabled') || $user === null) {
            return $query->whereRaw('1 = 0');
        }

        $userId = (int) $user->getAuthIdentifier();
        $superAdmin = WorkspaceAccess::isSuperAdmin($user);
        $member = WorkspaceAccess::memberFor($user);
        $activeMember = $member !== null && $member->is_active ? $member : null;

        return $query->where(function (Builder $q) use ($user, $userId, $superAdmin, $activeMember): void {
            // A project's documents, for whoever may use them.
            $q->whereIn('documents.project_id', Project::query()->documentsVisibleTo($user)->select('projects.id'));

            // Their own personal documents, while they are active.
            if ($superAdmin || $activeMember !== null) {
                $q->orWhere(fn (Builder $own): Builder => $own->whereNull('documents.project_id')->where('documents.owner_id', $userId));
            }

            // Opened to their organization, or shared with them.
            if ($activeMember !== null && $activeMember->organization_id !== null) {
                $memberId = $activeMember->id;

                $q->orWhere(fn (Builder $shared): Builder => $shared
                    ->where(fn (Builder $f): Builder => self::whereNotFrozen($f))
                    ->where(fn (Builder $o): Builder => self::whereInOrganization($o, (int) $activeMember->organization_id))
                    ->where(fn (Builder $w): Builder => $w
                        ->whereNotNull('documents.organization_access')
                        ->orWhereHas('shares', fn (Builder $share): Builder => $share->where('document_shares.member_id', $memberId))));
            }

            // Frozen documents, read-only, for super-admins.
            if ($superAdmin) {
                $q->orWhere(fn (Builder $frozen): Builder => $frozen
                    ->whereNull('documents.project_id')
                    ->whereNot(fn (Builder $f): Builder => self::whereNotFrozen($f)));
            }
        });
    }

    /**
     * Documents of an organization (WorkspaceAccess::documentOrganizationId):
     * a project's of the project's, a personal one of its owner's now.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public static function whereInOrganization(Builder $query, int $organizationId): Builder
    {
        return $query
            ->where(fn (Builder $project): Builder => $project
                ->whereNotNull('documents.project_id')
                ->whereHas('project', fn (Builder $p): Builder => $p->where('projects.organization_id', $organizationId)))
            ->orWhere(fn (Builder $personal): Builder => $personal
                ->whereNull('documents.project_id')
                ->whereExists(Member::query()
                    ->select('members.id')
                    ->whereColumn('members.user_id', 'documents.owner_id')
                    ->where('members.organization_id', $organizationId)
                    ->toBase()));
    }

    /**
     * Not frozen (WorkspaceAccess::isDocumentFrozen): a project's document, or
     * a personal one whose owner is a super-admin or an active person.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    private static function whereNotFrozen(Builder $query): Builder
    {
        return $query
            ->whereNotNull('documents.project_id')
            ->orWhereExists(Member::query()
                ->select('members.id')
                ->whereColumn('members.user_id', 'documents.owner_id')
                ->where('members.is_active', true)
                ->toBase())
            ->orWhereExists(User::query()
                ->select('users.id')
                ->whereColumn('users.id', 'documents.owner_id')
                ->whereIn('users.email', (array) config('project-management.super_admins', []))
                ->toBase());
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
