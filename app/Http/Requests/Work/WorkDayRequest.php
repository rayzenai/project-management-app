<?php

namespace App\Http\Requests\Work;

use App\Http\Requests\Concerns\ReadsDay;
use App\Http\Requests\Concerns\ReadsReportScope;
use App\Queries\Structure\OrganizationChoicesQuery;
use App\Queries\Structure\PeopleInViewQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Today's work, or any day's (ReadsDay), for an organization or someone's
 * team in it (ReadsReportScope): WorkspaceAccess::canViewTeamWork.
 */
class WorkDayRequest extends FormRequest
{
    use ReadsDay;
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
        return [...$this->scopeRules(), ...$this->dayRules()];
    }

    /**
     * The filters as applied, for the page to show and send back.
     *
     * @return array{organization: int, date: string, under: ?int}
     */
    public function appliedFilters(): array
    {
        return [
            'organization' => $this->organizationId(),
            'date' => $this->day()->toDateString(),
            'under' => $this->under()?->id,
        ];
    }

    protected function maySeeOrganization(Authenticatable $user, int $organizationId): bool
    {
        return WorkspaceAccess::canViewTeamWork($user, $organizationId);
    }
}
