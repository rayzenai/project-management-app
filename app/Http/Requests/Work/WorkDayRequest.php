<?php

namespace App\Http\Requests\Work;

use App\Http\Requests\Concerns\ReadsDay;
use App\Http\Requests\Concerns\ReadsReportScope;
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
     * @return array{organization: int, under: int|null, date: string}
     */
    public function appliedFilters(): array
    {
        return ['date' => $this->day()->toDateString(), ...$this->scopeFilters()];
    }

    protected function maySeeOrganization(Authenticatable $user, int $organizationId): bool
    {
        return WorkspaceAccess::canViewTeamWork($user, $organizationId);
    }
}
