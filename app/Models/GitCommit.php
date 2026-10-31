<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * A commit seen in a linked repository. Linked to the tasks its message
 * mentions; links are only ever added. A commit that vanished after a
 * force-push is marked `unreachable_at`, never deleted.
 *
 * @property int $id
 * @property int $git_repository_id
 * @property int|null $user_id
 * @property string $sha
 * @property string $message
 * @property string $author_name
 * @property string|null $author_username
 * @property string $url
 * @property CarbonImmutable $committed_at
 * @property CarbonImmutable|null $unreachable_at
 * @property bool $is_merge
 * @property int|null $files_changed
 * @property int|null $additions
 * @property int|null $deletions
 * @property int $code_added
 * @property int $code_removed
 * @property int $comment_added
 * @property int $comment_removed
 * @property int $blank_added
 * @property int $blank_removed
 * @property int $docs_added
 * @property int $docs_removed
 * @property int $generated_lines
 * @property string|null $stats_status ok, merge, too_large or missing
 * @property CarbonImmutable|null $stats_computed_at
 * @property string|null $checks_state passed, failed, running or none (Checks); null until asked
 * @property list<array{name: string, state: string, url: string|null}>|null $checks
 * @property CarbonImmutable|null $checks_checked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class GitCommit extends Model
{
    protected $fillable = [
        'git_repository_id',
        'user_id',
        'sha',
        'message',
        'author_name',
        'author_username',
        'url',
        'committed_at',
        'unreachable_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'committed_at' => 'datetime',
            'unreachable_at' => 'datetime',
            'is_merge' => 'boolean',
            'stats_computed_at' => 'datetime',
            'checks' => 'array',
            'checks_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<GitRepository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class, 'git_repository_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphToMany<Task, $this, TaskGitLink>
     */
    public function tasks(): MorphToMany
    {
        return $this->morphToMany(Task::class, 'linkable', 'task_git_links')->using(TaskGitLink::class)->withPivot('created_at');
    }
}
