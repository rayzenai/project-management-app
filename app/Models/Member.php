<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MemberFactory;
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
 * @property int $id
 * @property int|null $user_id
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
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Teams this member leads (pivot role = leader).
     *
     * @return BelongsToMany<Team, $this>
     */
    public function ledTeams(): BelongsToMany
    {
        return $this->teams()->wherePivot('role', 'leader');
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
     * the union of the project's teams' members, or every active member when
     * the project has no teams attached yet (so nothing breaks before teams
     * exist). Pickers, request validation, and quick-add `@name` resolution
     * all go through this scope.
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
            )
            ->orderBy('name');
    }

    protected static function newFactory(): MemberFactory
    {
        return MemberFactory::new();
    }
}
