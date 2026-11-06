<?php

namespace App\Models;

use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document given to one person (docs/superpowers/specs/2026-11-06-documents-workspace-design.md),
 * as Can edit or Can view. It counts only while the person is active and in
 * the document's organization now ({@see WorkspaceAccess::documentAccess()});
 * one that stops counting stays stored and gives nothing.
 *
 * @property int $id
 * @property int $document_id
 * @property int $member_id
 * @property string $permission
 * @property int|null $shared_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Document $document
 * @property-read Member $member
 * @property-read User|null $sharer
 */
class DocumentShare extends Model
{
    protected $fillable = ['document_id', 'member_id', 'permission', 'shared_by'];

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sharer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }
}
