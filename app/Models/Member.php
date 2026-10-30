<?php

namespace App\Models;

use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Database\Factories\MemberFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person in the workspace. Members are what tasks get assigned to; a member
 * optionally links to a User (`user_id`, one member per user) but
 * never needs one — unlinked members are managed people who don't log in.
 *
 * Every member belongs to an organization except a super-admin's own member.
 * `is_office_admin` makes them the administrator of that organization.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $organization_id
 * @property bool $is_office_admin
 * @property string $name
 * @property string|null $email
 * @property string|null $title
 * @property array<int, string>|null $coordinator_categories
 * @property string|null $coordinator_role
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'members';

    protected $fillable = [
        'user_id',
        'organization_id',
        'is_office_admin',
        'name',
        'email',
        'title',
        'coordinator_categories',
        'coordinator_role',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coordinator_categories' => 'array',
            'is_active' => 'boolean',
            'is_office_admin' => 'boolean',
        ];
    }

    /**
     * Resolve the member representing a login, creating it on first use so
     * new users just work in My Workspace and `Task::scopeMine`.
     */
    public static function forUser(mixed $user): self
    {
        return self::query()->firstOrCreate(
            ['user_id' => $user->getKey()],
            ['name' => $user->name, 'email' => $user->email],
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsToMany<Team, $this, TeamMembership>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->using(TeamMembership::class)->withPivot('id', 'role')->withTimestamps();
    }

    /**
     * Teams this member leads (pivot role = leader).
     *
     * @return BelongsToMany<Team, $this, TeamMembership>
     */
    public function ledTeams(): BelongsToMany
    {
        return $this->teams()->wherePivot('role', 'leader');
    }

    /**
     * Programs this member manages (`programs.manager_id`). The power
     * itself goes through {@see WorkspaceAccess::managedProgramIds()}.
     *
     * @return HasMany<Program, $this>
     */
    public function managedPrograms(): HasMany
    {
        return $this->hasMany(Program::class, 'manager_id');
    }

    /**
     * @return HasMany<ProjectAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ProjectAssignment::class);
    }

    /**
     * @param  Builder<Member>  $query
     * @return Builder<Member>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The single source of truth for who can be assigned within a project:
     * the union of the project's teams' members, or, for a public project
     * with no teams, every active member of its organization (a private
     * project with no teams has nobody to assign). Pickers,
     * request validation, and quick-add `@name` resolution all go through
     * this scope.
     *
     * @param  Builder<Member>  $query
     * @return Builder<Member>
     */
    public function scopeAssignableFor(Builder $query, Project $project): Builder
    {
        $teamIds = $project->teams()->pluck('teams.id');

        return $query
            ->active()
            ->when(
                $teamIds->isNotEmpty(),
                fn (Builder $q) => $q->whereHas('teams', fn (Builder $t) => $t->whereIn('teams.id', $teamIds)),
                fn (Builder $q) => $project->is_public
                    ? $q->where('organization_id', $project->organization_id)
                    : $q->whereRaw('1 = 0'),
            )
            ->orderBy('name');
    }

    /**
     * People the user may see in directories, pickers and rosters: everyone
     * for a super-admin; for anyone else themselves and the people on their
     * teams, plus the people on the teams of the projects in the programs
     * they manage, and, for an office admin, everyone in their organization
     * and anyone on its teams (including outsiders on its cross-organization
     * teams). See {@see WorkspaceAccess}.
     *
     * @param  Builder<Member>  $query
     * @return Builder<Member>
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): Builder
    {
        if (WorkspaceAccess::isSuperAdmin($user)) {
            return $query;
        }

        $self = WorkspaceAccess::memberFor($user);

        if ($self === null) {
            return $query->whereRaw('1 = 0');
        }

        $adminOrganizationId = WorkspaceAccess::officeAdminOrganizationId($user);
        $programIds = WorkspaceAccess::managedProgramIds($user);

        return $query->where(function (Builder $q) use ($self, $adminOrganizationId, $programIds): void {
            $q->whereKey($self->getKey())
                ->orWhereHas('teams', fn (Builder $t) => $t->whereIn('teams.id', $self->teams()->select('teams.id')));

            if ($programIds !== []) {
                $q->orWhereHas('teams.projects', fn (Builder $p) => Project::inPrograms($p, $programIds, $self));
            }

            if ($adminOrganizationId !== null) {
                $q->orWhere('organization_id', $adminOrganizationId)
                    ->orWhereHas('teams', fn (Builder $t) => $t->where('teams.organization_id', $adminOrganizationId));
            }
        });
    }

    protected static function newFactory(): MemberFactory
    {
        return MemberFactory::new();
    }
}
