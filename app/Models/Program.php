<?php

namespace App\Models;

use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Database\Factories\ProgramFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A named collection of projects in one organization, with one program
 * manager. A project is in at most one program; deleting a program keeps its
 * projects. `manager_id` is null only after the manager's member row was
 * pruned.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int|null $manager_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Program extends Model
{
    /** @use HasFactory<ProgramFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'description',
        'manager_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (Program $program): void {
            if (empty($program->slug)) {
                $base = Str::slug($program->name) ?: 'program';
                $slug = $base;
                $i = 2;
                while (static::query()->where('slug', $slug)->whereKeyNot($program->getKey())->exists()) {
                    $slug = $base.'-'.$i;
                    $i++;
                }
                $program->slug = $slug;
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
     * @return BelongsTo<Member, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'manager_id');
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Programs the user may see: all for a super-admin, every one of the
     * organization an office admin runs, otherwise the ones they manage. The
     * query twin of {@see WorkspaceAccess::canViewProgram()}; keep them in
     * step.
     *
     * @param  Builder<Program>  $query
     * @return Builder<Program>
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): Builder
    {
        if (WorkspaceAccess::isSuperAdmin($user)) {
            return $query;
        }

        $adminOrganizationId = WorkspaceAccess::officeAdminOrganizationId($user);

        if ($adminOrganizationId !== null) {
            return $query->where('organization_id', $adminOrganizationId);
        }

        return $query->whereKey(WorkspaceAccess::managedProgramIds($user));
    }

    protected static function newFactory(): ProgramFactory
    {
        return ProgramFactory::new();
    }
}
