<?php

namespace App\Services\Structure;

use App\Models\Designation;
use App\Models\Member;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;

/**
 * Removes a designation from its organization's list. The people who held
 * it stay, with no designation (the foreign key nulls it too; this does it
 * first so the result does not depend on the database enforcing it).
 */
class DeleteDesignationService
{
    public function execute(Designation $designation): ServiceResult
    {
        DB::transaction(function () use ($designation): void {
            Member::withTrashed()->where('designation_id', $designation->getKey())->update(['designation_id' => null]);
            $designation->delete();
        });

        return ServiceResult::success(null, "Removed {$designation->name}. The people who held it have no designation now.");
    }
}
