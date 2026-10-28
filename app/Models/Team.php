<?php

namespace App\Models;

use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Database\Factories\TeamFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A group of members inside one organization (its home). A super-admin may
 * mark it cross-organization, which lets anyone from any organization join;
 * its projects still belong to the home organization.
 *
 * @property int $id
 * @property int $organization_id
 * @property bool $is_cross_organization
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $color
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read int|null $members_count
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'teams';

    protected $fillable = [
        'organization_id',
        'is_cross_organization',
        'name',
        'slug',
        'description',
        'color',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_cross_organization' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Team $team) {
            if (empty($team->slug)) {
                $base = Str::slug($team->name) ?: 'team';
                $slug = $base;
                $i = 2;
                while (static::withTrashed()->where('slug', $slug)->whereKeyNot($team->getKey())->exists()) {
                    $slug = $base.'-'.$i;
                    $i++;
                }
                $team->slug = $slug;
            }
        });
    }

    /**
     * Teams the user may see: all for a super-admin, every team of the
     * organization an office admin runs, and otherwise the teams they are
     * on. A leader never sees the other teams of their organization.
     *
     * @param  Builder<Team>  $query
     * @return Builder<Team>
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
            $q->whereHas('members', fn (Builder $m) => $m->where('members.id', $member->getKey()));

            if ($adminOrganizationId !== null) {
                $q->orWhere('organization_id', $adminOrganizationId);
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsToMany<Member, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Members whose pivot role marks them as a leader of this team.
     *
     * @return BelongsToMany<Member, $this>
     */
    public function leaders(): BelongsToMany
    {
        return $this->members()->wherePivot('role', 'leader');
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
    }

    /**
     * The one project this team works on (see {@see Project::teams()}).
     */
    public function project(): ?Project
    {
        return $this->projects->first();
    }

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }
}
