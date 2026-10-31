<?php

namespace App\Services\Structure;

use App\Models\Designation;
use App\Models\Member;
use App\Support\OrgChart;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;

/**
 * Keeps reporting lines whole when someone leaves: the people who reported
 * to them now report to that person's own higher authority (or to no one).
 * Run by MemberObserver when a member is deleted (soft or hard) and when
 * they move to another organization; then they also lose their own higher
 * authority, and a designation of the organization they left. Deactivation
 * keeps every line.
 */
class MoveReportsUpService
{
    /**
     * @param  int|null  $bossId  the leaving member's higher authority before they left
     */
    public function execute(Member $member, ?int $bossId, bool $leftOrganization = false): ServiceResult
    {
        $id = (int) $member->getKey();
        $bossId = $bossId === $id ? null : $bossId;

        $moved = DB::transaction(function () use ($member, $id, $bossId, $leftOrganization): int {
            $moved = Member::withTrashed()
                ->where('reports_to_id', $id)
                ->whereKeyNot($id)
                ->update(['reports_to_id' => $bossId]);

            if ($leftOrganization) {
                $keepsDesignation = $member->designation_id !== null && Designation::query()
                    ->whereKey($member->designation_id)
                    ->where('organization_id', $member->organization_id)
                    ->exists();

                $cleared = ['reports_to_id' => null] + ($keepsDesignation ? [] : ['designation_id' => null]);

                Member::withTrashed()->whereKey($id)->update($cleared);

                // In memory too, without a save of its own: the caller may be
                // mid-save, and a second save would lose what changed.
                foreach ($cleared as $attribute => $value) {
                    $member->setAttribute($attribute, $value);
                }
            }

            return $moved;
        });

        OrgChart::flush();

        return ServiceResult::success(['moved' => $moved]);
    }
}
