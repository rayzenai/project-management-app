<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\Concerns\ReadsDayRange;
use App\Http\Requests\Concerns\ReadsReportScope;
use App\Queries\Structure\OrganizationChoicesQuery;
use App\Queries\Structure\PeopleInViewQuery;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Attendance totals over whole days (ReadsDayRange; the last 7 ending today
 * unless given), for an organization or someone's team in it
 * (ReadsReportScope): whoever may open its register.
 */
class AttendanceSummaryRequest extends FormRequest
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
     * @return array{from: string, to: string, under: int|null, organization: int}
     */
    public function appliedFilters(): array
    {
        [$from, $to] = $this->range();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'under' => $this->under()?->id,
            'organization' => $this->organizationId(),
        ];
    }

    protected function maySeeOrganization(Authenticatable $user, int $organizationId): bool
    {
        return WorkspaceAccess::canViewAttendanceRegister($user, $organizationId);
    }

    protected function aboutAttendance(): bool
    {
        return true;
    }

    protected function defaultFrom(CarbonImmutable $to): CarbonImmutable
    {
        return $to->subDays(6);
    }
}
