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
 * @property string $sha
 * @property string $message
 * @property string $author_name
 * @property string|null $author_username
 * @property string $url
 * @property CarbonImmutable $committed_at
 * @property CarbonImmutable|null $unreachable_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class GitCommit extends Model
{
    protected $fillable = [
        'git_repository_id',
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
     * @return MorphToMany<Task, $this, TaskGitLink>
     */
    public function tasks(): MorphToMany
    {
        return $this->morphToMany(Task::class, 'linkable', 'task_git_links')->using(TaskGitLink::class)->withPivot('created_at');
    }
}
