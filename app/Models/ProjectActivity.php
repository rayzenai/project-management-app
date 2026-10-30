<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An audit entry. It always names its project; `task_id` is null when it
 * concerns the project itself, such as its team.
 *
 * @property int $id
 * @property int|null $project_id
 * @property int|null $task_id
 * @property int|null $user_id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $action
 * @property array<string, mixed>|null $changes
 * @property string $description
 * @property bool $is_public
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectActivity extends Model
{
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';

    public const ACTION_RESTORED = 'restored';

    public const ACTION_STATUS_CHANGED = 'status_changed';

    public const ACTION_PROGRESS_CHANGED = 'progress_changed';

    public const ACTION_COMPLETED = 'completed';

    public const ACTION_REOPENED = 'reopened';

    public const ACTION_COMMENTED = 'commented';

    protected $table = 'project_activities';

    protected $fillable = [
        'project_id',
        'task_id',
        'user_id',
        'subject_type',
        'subject_id',
        'action',
        'changes',
        'description',
        'is_public',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'is_public' => 'boolean',
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
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<ProjectActivity>  $query
     * @return Builder<ProjectActivity>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * Entries the user may read: everything except the git ones (a linked
     * repository, a pull request, a branch, a commit, someone's access to a
     * repository), which name code and so follow
     * {@see Project::scopeCodeVisibleTo()}. The one place that
     * rule is applied to activity; every reader of the log goes through it.
     *
     * @param  Builder<ProjectActivity>  $query
     * @return Builder<ProjectActivity>
     */
    public function scopeCodeVisibleTo(Builder $query, ?Authenticatable $user): Builder
    {
        $gitTypes = array_map(
            fn (string $model): string => (new $model)->getMorphClass(),
            [GitRepository::class, GitBranch::class, GitCommit::class, GitPullRequest::class, GitRepositoryGrant::class],
        );

        return $query->where(function (Builder $q) use ($gitTypes, $user): void {
            $q->whereNotIn('subject_type', $gitTypes)
                ->orWhereIn('project_id', Project::query()->codeVisibleTo($user)->select('projects.id'));
        });
    }

    /**
     * @param  Builder<ProjectActivity>  $query
     * @return Builder<ProjectActivity>
     */
    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
