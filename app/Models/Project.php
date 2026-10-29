<?php

namespace App\Models;

use App\Support\ProjectKeys;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $slug
 * @property string $key
 * @property string $title
 * @property string|null $title_np
 * @property string|null $description
 * @property string|null $description_np
 * @property bool $is_public
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property int $last_task_number
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read int|null $tasks_count
 * @property-read int|null $done_tasks_count
 * @property-read int|null $overdue_tasks_count
 * @property-read string $code
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $table = 'projects';

    protected $fillable = [
        'organization_id',
        'slug',
        'key',
        'title',
        'title_np',
        'description',
        'description_np',
        'is_public',
        'starts_at',
        'ends_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The project half of a task code: its stored key (SG in SG-12). Only a
     * project not saved yet falls back to what its title suggests; a saved
     * one loaded without its key fails loudly instead of guessing.
     *
     * @return Attribute<string, never>
     */
    protected function code(): Attribute
    {
        return Attribute::get(fn (): string => $this->exists ? $this->key : ($this->key ?? ProjectKeys::suggest((string) $this->title)));
    }

    protected static function booted(): void
    {
        // A project made without a key (a factory, a console one-off) gets the
        // one its title suggests; the services pass theirs explicitly.
        static::creating(function (Project $project): void {
            if (($project->attributes['key'] ?? null) === null) {
                $project->key = ProjectKeys::unique((int) $project->organization_id, ProjectKeys::suggest((string) $project->title));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Keys the project used to have; they keep resolving to it.
     *
     * @return HasMany<ProjectKeyAlias, $this>
     */
    public function keyAliases(): HasMany
    {
        return $this->hasMany(ProjectKeyAlias::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('sort_order')->orderByRaw("CAST(metadata->>'item_number' AS BIGINT) ASC NULLS LAST");
    }

    /**
     * Forge repositories linked to the project.
     *
     * @return HasMany<GitRepository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(GitRepository::class);
    }

    /**
     * The link to the project's team. The pivot is unique on both sides, so a
     * project has exactly one team and a team serves exactly one project;
     * the visibility scopes query through this relation.
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withTimestamps();
    }

    /**
     * The project's own team, created with it.
     */
    public function team(): ?Team
    {
        return $this->teams->first();
    }

    /**
     * The project's team, made now if it has none yet (named after it, in
     * its organization).
     */
    public function ensureTeam(): Team
    {
        $team = $this->team();

        if ($team === null) {
            $team = Team::create(['name' => $this->title, 'organization_id' => $this->organization_id]);
            $this->teams()->attach($team->id);
            $this->unsetRelation('teams');
        }

        return $team;
    }

    /**
     * The project's manager: its team's one leader, if one has been chosen.
     */
    public function manager(): ?Member
    {
        return $this->team()?->leaders->first();
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * Projects the user may see: those of their organization's office they
     * administer, public ones of their own organization, and any attached
     * to a team they are on (which is how a cross-organization team's
     * outsiders see its projects). Super-admins see everything. The query
     * twin of {@see WorkspaceAccess::canViewProject()}; keep them in step.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): Builder
    {
        if (WorkspaceAccess::isSuperAdmin($user)) {
            return $query;
        }

        $member = WorkspaceAccess::memberFor($user);

        if ($member === null) {
            return $query->whereRaw('1 = 0');
        }

        $adminOrganizationId = WorkspaceAccess::officeAdminOrganizationId($user);

        return $query->where(function (Builder $q) use ($member, $adminOrganizationId): void {
            $q->whereHas('teams.members', fn (Builder $m): Builder => $m->where('members.id', $member->getKey()));

            if ($member->organization_id !== null) {
                $q->orWhere(fn (Builder $public): Builder => $public
                    ->where('is_public', true)
                    ->where('organization_id', $member->organization_id));
            }

            if ($adminOrganizationId !== null) {
                $q->orWhere('organization_id', $adminOrganizationId);
            }
        });
    }

    /**
     * Projects whose code (linked repositories, and the branches, commits
     * and pull requests on their tasks) the user may see: in an organization
     * with git, those whose team they are on (any role, while active), every
     * one of the office they administer, and every one for a super-admin.
     * Not other viewers of a public project. The query twin of
     * {@see WorkspaceAccess::canViewProjectCode()}; keep them in step.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeCodeVisibleTo(Builder $query, ?Authenticatable $user): Builder
    {
        $query->whereIn('organization_id', GitConnection::query()->select('organization_id'));

        if (WorkspaceAccess::isSuperAdmin($user)) {
            return $query;
        }

        $member = WorkspaceAccess::memberFor($user);

        // An office admin is an active member too.
        if ($member === null || ! $member->is_active) {
            return $query->whereRaw('1 = 0');
        }

        $adminOrganizationId = WorkspaceAccess::officeAdminOrganizationId($user);

        return $query->where(function (Builder $q) use ($member, $adminOrganizationId): void {
            $q->whereHas('teams.members', fn (Builder $m): Builder => $m->where('members.id', $member->getKey()));

            if ($adminOrganizationId !== null) {
                $q->orWhere('organization_id', $adminOrganizationId);
            }
        });
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isArchived(): Attribute
    {
        return Attribute::get(fn (): bool => $this->archived_at !== null);
    }

    public function archive(): void
    {
        if ($this->archived_at === null) {
            $this->forceFill(['archived_at' => now()])->save();
        }
    }

    public function restore(): void
    {
        if ($this->archived_at !== null) {
            $this->forceFill(['archived_at' => null])->save();
        }
    }

    /**
     * Short, compact label suitable for cards and chips.
     * Collapses any title containing "100-Day Plan" down to just "100-Day".
     *
     * @return Attribute<string, never>
     */
    protected function shortLabel(): Attribute
    {
        return Attribute::get(function (): string {
            $title = (string) ($this->title ?? '');

            if (str_contains($title, '100-Day Plan')) {
                return '100-Day';
            }

            return $title;
        });
    }

    protected static function newFactory(): ProjectFactory
    {
        return ProjectFactory::new();
    }
}
