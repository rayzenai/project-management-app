<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * A branch of a linked repository. Linked to the tasks its name mentions.
 *
 * @property int $id
 * @property int $git_repository_id
 * @property string $name
 * @property string|null $head_sha
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class GitBranch extends Model
{
    protected $fillable = ['git_repository_id', 'name', 'head_sha'];

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
