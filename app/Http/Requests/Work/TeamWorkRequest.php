<?php

namespace App\Http\Requests\Work;

use App\Http\Requests\Work\Concerns\ReadsWorkRange;
use App\Http\Requests\Work\Concerns\ReadsWorkScope;
use App\Queries\Work\WorkFiltersQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A team's work: an organization (and optionally someone's team, see
 * ReadsWorkScope) over a range of days (ReadsWorkRange).
 */
class TeamWorkRequest extends FormRequest
{
    use ReadsWorkRange;
    use ReadsWorkScope;

    public function authorize(WorkFiltersQuery $filters): bool
    {
        return $this->authorizeWorkScope($filters);
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
}
