<?php

namespace App\Http\Requests\Work;

use App\Http\Requests\Concerns\ReadsDayRange;
use App\Http\Requests\Concerns\ReadsReportScope;
use App\Queries\Structure\OrganizationChoicesQuery;
use App\Queries\Structure\PeopleInViewQuery;
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

    public function authorize(OrganizationChoicesQuery $organizations, PeopleInViewQuery $people): bool
    {
        return $this->authorizeScope($organizations, $people);
    }

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
     * @return array{organization: int, from: string, to: string, under: ?int}
     */
    public function appliedFilters(): array
    {
        [$from, $to] = $this->range();

        return [
            'organization' => $this->organizationId(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'under' => $this->under()?->id,
        ];
    }

    protected function maySeeOrganization(Authenticatable $user, int $organizationId): bool
    {
        return WorkspaceAccess::canViewTeamWork($user, $organizationId);
    }
}
