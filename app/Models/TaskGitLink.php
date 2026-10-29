<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A task mentioned by a branch, commit or pull request (`task_git_links`).
 * A pivot model so TaskGitLinkObserver hears every link made through the
 * relations. Links are never updated, so only `created_at` is kept.
 *
 * @property int $id
 * @property int $task_id
 * @property int $git_repository_id
 * @property string $linkable_type
 * @property int $linkable_id
 * @property CarbonImmutable|null $created_at
 */
class TaskGitLink extends MorphPivot
{
    protected $table = 'task_git_links';

    public $incrementing = true;

    /**
     * No `updated_at`, whichever side the link was made through (a pivot
     * otherwise borrows its parent's column).
     */
    public function getUpdatedAtColumn(): ?string
    {
        return null;
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
