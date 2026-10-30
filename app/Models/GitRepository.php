<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A forge repository linked to a project. Its branches, commits and pull
 * requests, and their task links, come in through GitIngest; unlinking
 * deletes the row (and all of them) and leaves the forge alone.
 *
 * @property int $id
 * @property int $git_connection_id
 * @property int $project_id
 * @property int $forge_id
 * @property string $owner
 * @property string $name
 * @property string $full_name
 * @property string $html_url
 * @property string $clone_url
 * @property string $ssh_url
 * @property string $default_branch
 * @property CarbonImmutable|null $last_pushed_at
 * @property CarbonImmutable|null $reconciled_at
 * @property bool $protects_main
 * @property CarbonImmutable|null $access_synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read GitConnection $gitConnection
 * @property-read Project $project
 */
class GitRepository extends Model
{
    protected $fillable = [
        'git_connection_id',
        'project_id',
        'forge_id',
        'owner',
        'name',
        'full_name',
        'html_url',
        'clone_url',
        'ssh_url',
        'default_branch',
        'last_pushed_at',
        'reconciled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'forge_id' => 'integer',
            'last_pushed_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'protects_main' => 'boolean',
            'access_synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<GitConnection, $this>
     */
    public function gitConnection(): BelongsTo
    {
        return $this->belongsTo(GitConnection::class, 'git_connection_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<GitBranch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(GitBranch::class);
    }

    /**
     * @return HasMany<GitCommit, $this>
     */
    public function commits(): HasMany
    {
        return $this->hasMany(GitCommit::class);
    }

    /**
     * @return HasMany<GitPullRequest, $this>
     */
    public function pullRequests(): HasMany
    {
        return $this->hasMany(GitPullRequest::class);
    }

    /**
     * People given access to the repository on the forge, beyond its
     * maintainers.
     *
     * @return HasMany<GitRepositoryGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(GitRepositoryGrant::class);
    }

    /**
     * @return HasMany<GitPullRequest, $this>
     */
    public function openPullRequests(): HasMany
    {
        return $this->pullRequests()->where('state', GitPullRequest::STATE_OPEN);
    }
}
