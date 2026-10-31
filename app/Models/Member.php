<?php

namespace App\Models;

use App\Support\OrgChart;
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
 * Every member belongs to an organization; a super-admin's own member may
 * belong to none (a super-admin can give it one).
 * `is_office_admin` makes them the administrator of that organization.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $organization_id
 * @property bool $is_office_admin
 * @property string $name
 * @property string|null $email
 * @property array<int, string>|null $coordinator_categories
 * @property string|null $coordinator_role
 * @property bool $is_active
 * @property int|null $designation_id
 * @property int|null $reports_to_id the person they report to (their higher authority)
 * @property bool $attendance_exempt
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
        'coordinator_categories',
        'coordinator_role',
        'is_active',
        'designation_id',
        'reports_to_id',
        'attendance_exempt',
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
            'attendance_exempt' => 'boolean',
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
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /**
     * The person this member reports to. Everyone above and below goes
     * through {@see OrgChart}.
     *
     * @return BelongsTo<Member, $this>
     */
    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_id');
    }

    /**
     * The people who report to this member directly.
     *
     * @return HasMany<Member, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'reports_to_id');
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
     * Access this member was given to linked repositories.
     *
     * @return HasMany<GitRepositoryGrant, $this>
     */
    public function repositoryGrants(): HasMany
    {
        return $this->hasMany(GitRepositoryGrant::class);
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
     * People attendance expects: everyone not exempt from it.
     *
     * @param  Builder<Member>  $query
     * @return Builder<Member>
     */
    public function scopeAttending(Builder $query): Builder
    {
        return $query->where('attendance_exempt', false);
    }

    /**
     * Who can be assigned within a project as things stand: the union of
     * the project's teams' members, or, for a public project with no teams,
     * every active member of its organization (a private project with no
     * teams has nobody to assign). Pickers, request validation, and
     * quick-add `@name` resolution all go through this scope; for whoever
     * runs the team, App\Support\Assignees adds the people they may bring
     * onto it.
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
