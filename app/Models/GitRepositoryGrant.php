<?php

namespace App\Models;

use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's explicit access to a linked repository on the forge, `read` or
 * `write`. Who may give it to whom is
 * {@see WorkspaceAccess::canGrantRepositoryAccessTo()}. It counts (is
 * effective) only while the person is active and belongs to the
 * repository's project's organization or is on that project's team.
 *
 * @property int $id
 * @property int $git_repository_id
 * @property int $member_id
 * @property string $permission
 * @property int|null $granted_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read GitRepository $repository
 * @property-read Member $member
 * @property-read User|null $grantedBy
 */
class GitRepositoryGrant extends Model
{
    public const READ = 'read';

    public const WRITE = 'write';

    public const PERMISSIONS = [self::READ, self::WRITE];

    protected $fillable = ['git_repository_id', 'member_id', 'permission', 'granted_by'];

    /**
     * @return BelongsTo<GitRepository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class, 'git_repository_id');
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
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * Grants that count: the person is active and belongs to the
     * repository's project's organization, or is on that project's team (a
     * cross-organization team's outsiders). The others stay stored but give
     * no access.
     *
     * @param  Builder<GitRepositoryGrant>  $query
     */
    public function scopeEffective(Builder $query): void
    {
        $query->whereHas('member', fn (Builder $member): Builder => $member
            ->where('members.is_active', true)
            ->where(fn (Builder $member): Builder => $member
                ->whereIn('members.organization_id', Project::query()
                    ->join('git_repositories', 'git_repositories.project_id', '=', 'projects.id')
                    ->whereColumn('git_repositories.id', 'git_repository_grants.git_repository_id')
                    ->select('projects.organization_id'))
                ->orWhereHas('teams.projects.repositories', fn (Builder $repository): Builder => $repository
                    ->whereColumn('git_repositories.id', 'git_repository_grants.git_repository_id'))));
    }
}
