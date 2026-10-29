<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A key a project used to have. It keeps resolving to the project and stays
 * reserved in its organization, so an old commit never points elsewhere.
 *
 * @property int $id
 * @property int $project_id
 * @property int $organization_id
 * @property string $key
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectKeyAlias extends Model
{
    protected $fillable = ['project_id', 'organization_id', 'key'];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
