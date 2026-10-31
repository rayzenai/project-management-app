<?php

namespace App\Services\Structure;

use App\Models\Designation;
use App\Services\Structure\Concerns\NamesDesignations;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;

/**
 * Renames a designation or changes its rank (where it sits in the list).
 * The people who hold it keep it.
 */
class UpdateDesignationService
{
    use NamesDesignations;

    /**
     * @param  array{name?: string, rank?: int}  $attributes
     */
    public function execute(Designation $designation, array $attributes): ServiceResult
    {
        return DB::transaction(function () use ($designation, $attributes): ServiceResult {
            $this->lockOrganization($designation->organization_id);

            if (array_key_exists('name', $attributes)) {
                $name = trim($attributes['name']);

                if ($this->nameTaken($designation->organization_id, $name, $designation)) {
                    return ServiceResult::failure($this->takenError($name)['name'], 422, $this->takenError($name));
                }

                $designation->name = $name;
            }

            if (array_key_exists('rank', $attributes)) {
                $designation->rank = $attributes['rank'];
            }

            $designation->save();

            return ServiceResult::success($designation, "Saved {$designation->name}.");
        });
    }
}
