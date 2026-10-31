<?php

namespace App\Services\Structure;

use App\Models\Designation;
use App\Models\Organization;
use App\Services\Structure\Concerns\NamesDesignations;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;

/**
 * Adds a designation to an organization's list. `rank` orders the list,
 * smaller first (more senior); 100 when not given.
 */
class CreateDesignationService
{
    use NamesDesignations;

    public const DEFAULT_RANK = 100;

    /**
     * @param  array{name: string, rank?: int|null}  $attributes
     */
    public function execute(Organization $organization, array $attributes): ServiceResult
    {
        $name = trim($attributes['name']);

        return DB::transaction(function () use ($organization, $attributes, $name): ServiceResult {
            $this->lockOrganization((int) $organization->getKey());

            if ($this->nameTaken((int) $organization->getKey(), $name)) {
                return ServiceResult::failure($this->takenError($name)['name'], 422, $this->takenError($name));
            }

            $designation = Designation::query()->create([
                'organization_id' => $organization->getKey(),
                'name' => $name,
                'rank' => $attributes['rank'] ?? self::DEFAULT_RANK,
            ]);

            return ServiceResult::success($designation, "Added {$name}.");
        });
    }
}
