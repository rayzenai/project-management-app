<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\Attendance\Concerns\ResolvesAttendanceScope;
use App\Queries\Attendance\AttendanceRegisterQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One day of the attendance register (`?date=`, today unless given), for
 * an organization or `?under=` someone in it (ResolvesAttendanceScope).
 */
class AttendanceRegisterRequest extends FormRequest
{
    use ResolvesAttendanceScope;

    public function authorize(AttendanceRegisterQuery $query): bool
    {
        return $this->authorizeScope($query);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'under' => ['nullable', 'integer', 'min:1'],
            'organization' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function day(): CarbonImmutable
    {
        return $this->filled('date')
            ? CarbonImmutable::parse($this->string('date')->value())->startOfDay()
            : CarbonImmutable::today();
    }

    /**
     * The filters as applied, for the page to show and send back.
     *
     * @return array{date: string, under: int|null, organization: int}
     */
    public function appliedFilters(): array
    {
        return [
            'date' => $this->day()->toDateString(),
            'under' => $this->under()?->id,
            'organization' => $this->organizationId(),
        ];
    }
}
