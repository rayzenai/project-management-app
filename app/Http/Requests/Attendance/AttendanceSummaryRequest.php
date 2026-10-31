<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\Attendance\Concerns\ResolvesAttendanceScope;
use App\Queries\Attendance\AttendanceRegisterQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Attendance totals over whole days (the last 7 ending today unless
 * given, at most 92), for an organization or `?under=` someone in it
 * (ResolvesAttendanceScope).
 */
class AttendanceSummaryRequest extends FormRequest
{
    use ResolvesAttendanceScope;

    public const DEFAULT_DAYS = 7;

    public const MAX_DAYS = 92;

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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'under' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }

            [$from, $to] = $this->range();

            if ($to->lt($from)) {
                $validator->errors()->add('to', "The end date can't be before the start date.");
            } elseif ((int) round($from->diffInDays($to)) + 1 > self::MAX_DAYS) {
                $validator->errors()->add('to', 'Choose a range of at most '.self::MAX_DAYS.' days.');
            }
        });
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $to = $this->filled('to') ? CarbonImmutable::parse($this->string('to')->value())->startOfDay() : CarbonImmutable::today();
        $from = $this->filled('from') ? CarbonImmutable::parse($this->string('from')->value())->startOfDay() : $to->subDays(self::DEFAULT_DAYS - 1);

        return [$from, $to];
    }

    /**
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
}
