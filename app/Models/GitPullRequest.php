<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * A pull request of a linked repository. Linked to the tasks its title and
 * body (upper-case keys) and head branch (keys in any case) mention; the
 * links follow every edit.
 *
 * @property int $id
 * @property int $git_repository_id
 * @property int $number
 * @property string $title
 * @property string|null $body
 * @property string $state
 * @property string $url
 * @property string $head_branch
 * @property string|null $author_username
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $merged_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $forge_updated_at
 * @property string|null $head_sha its head commit
 * @property string|null $checks_state that commit's checks (Checks), null until asked
 * @property list<array{name: string, state: string, url: string|null}>|null $checks
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class GitPullRequest extends Model
{
    public const STATE_OPEN = 'open';

    public const STATE_CLOSED = 'closed';

    public const STATE_MERGED = 'merged';

    protected $fillable = [
        'git_repository_id',
        'number',
        'title',
        'body',
        'state',
        'url',
        'head_branch',
        'author_username',
        'opened_at',
        'merged_at',
        'closed_at',
        'forge_updated_at',
        'head_sha',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'opened_at' => 'datetime',
            'merged_at' => 'datetime',
            'closed_at' => 'datetime',
            'forge_updated_at' => 'datetime',
            'checks' => 'array',
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
