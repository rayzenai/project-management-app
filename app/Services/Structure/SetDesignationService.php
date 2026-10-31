<?php

namespace App\Services\Structure;

use App\Models\Designation;
use App\Models\Member;
use App\Support\ServiceResult;

/**
 * Gives a member one of their organization's designations, or clears it.
 * Logs nothing in project activity: the organogram is the record.
 */
class SetDesignationService
{
    public function execute(Member $member, ?int $designationId): ServiceResult
    {
        if ($member->organization_id === null) {
            return $this->refuse('Only people in an organization have a designation.');
        }

        if ($designationId === null) {
            $member->update(['designation_id' => null]);
            $member->setRelation('designation', null);

            return ServiceResult::success($member, "{$member->name} has no designation now.");
        }

        $designation = Designation::query()
            ->whereKey($designationId)
            ->where('organization_id', $member->organization_id)
            ->first();

        if ($designation === null) {
            return $this->refuse('Choose one of the organization\'s designations.');
        }

        $member->update(['designation_id' => $designation->getKey()]);
        $member->setRelation('designation', $designation);

        return ServiceResult::success($member, "{$member->name} is now {$designation->name}.");
    }

    private function refuse(string $message): ServiceResult
    {
        return ServiceResult::failure($message, 422, ['designation_id' => $message]);
    }
}
