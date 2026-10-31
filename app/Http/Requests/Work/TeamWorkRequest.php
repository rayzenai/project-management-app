<?php

namespace App\Http\Requests\Work;

use App\Http\Requests\Concerns\ReadsDayRange;
use App\Http\Requests\Concerns\ReadsReportScope;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A team's work over a range of days (ReadsDayRange), for an organization
 * or someone's team in it (ReadsReportScope):
 * WorkspaceAccess::canViewTeamWork.
 */
class TeamWorkRequest extends FormRequest
{
    use ReadsDayRange;
    use ReadsReportScope;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...$this->scopeRules(), ...$this->rangeRules()];
    }

    /**
     * The filters as applied, for the page to show and send back.
     *
     * @return array{organization: int, under: int|null, from: string, to: string}
     */
    public function appliedFilters(): array
    {
        [$from, $to] = $this->range();

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), ...$this->scopeFilters()];
    }

    protected function maySeeOrganization(Authenticatable $user, int $organizationId): bool
    {
        return WorkspaceAccess::canViewTeamWork($user, $organizationId);
    }
}
