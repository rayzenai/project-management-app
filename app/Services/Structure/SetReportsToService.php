<?php

namespace App\Services\Structure;

use App\Models\Member;
use App\Models\Organization;
use App\Support\OrgChart;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;

/**
 * Sets the one person a member reports to (their higher authority), or
 * clears it. The higher authority must be an active, current person of the
 * member's own organization, never the member, and never someone already
 * below them (that would close a loop). The organization row is locked, so
 * two changes in one organization run one after the other and cannot close
 * a loop between them. Logs nothing in project activity: the organogram is
 * the record.
 */
class SetReportsToService
{
    /**
     * @param  Member|int|null  $boss  the higher authority (or their id); null: no one
     */
    public function execute(Member $member, Member|int|null $boss): ServiceResult
    {
        $bossId = $boss instanceof Member ? (int) $boss->getKey() : $boss;

        $result = DB::transaction(function () use ($member, $bossId): ServiceResult {
            if ($member->organization_id === null) {
                return $this->refuse('Only people in an organization report to someone.');
            }

            Organization::query()->whereKey($member->organization_id)->lockForUpdate()->first();

            if ($bossId === null) {
                $member->update(['reports_to_id' => null]);

                return ServiceResult::success($member, "{$member->name} reports to no one now.");
            }

            $bossRow = Member::query()->whereKey($bossId)->lockForUpdate()->first();

            if ($bossRow === null || (int) $bossRow->organization_id !== (int) $member->organization_id) {
                return $this->refuse('Choose someone in the same organization.');
            }

            if ($bossRow->is($member)) {
                return $this->refuse("{$member->name} can't report to themselves.");
            }

            if (! $bossRow->is_active) {
                return $this->refuse("{$bossRow->name} is deactivated. Choose someone active.");
            }

            OrgChart::flush();

            if (OrgChart::wouldLoop($member, $bossRow)) {
                return $this->refuse(sprintf(
                    '%s already reports to %s, so %s can\'t report to %s.',
                    $bossRow->name,
                    $member->name,
                    $this->firstName($member),
                    $this->firstName($bossRow),
                ));
            }

            $member->update(['reports_to_id' => $bossRow->getKey()]);
            $member->setRelation('reportsTo', $bossRow);

            return ServiceResult::success($member, "{$member->name} now reports to {$bossRow->name}.");
        });

        OrgChart::flush();

        return $result;
    }

    private function refuse(string $message): ServiceResult
    {
        return ServiceResult::failure($message, 422, ['reports_to_id' => $message]);
    }

    private function firstName(Member $member): string
    {
        return explode(' ', trim($member->name))[0];
    }
}
