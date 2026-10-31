<?php

namespace App\Http\Requests\Work;

use App\Http\Requests\Work\Concerns\ReadsWorkScope;
use App\Queries\Work\WorkFiltersQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Today's work: an organization (and optionally someone's team, see
 * ReadsWorkScope) on one day, `date` (YYYY-MM-DD, today unless given).
 */
class WorkDayRequest extends FormRequest
{
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
        return [
            ...$this->scopeRules(),
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function day(): CarbonImmutable
    {
        return $this->filled('date') ? CarbonImmutable::parse($this->string('date')->value())->startOfDay() : CarbonImmutable::today();
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
}
