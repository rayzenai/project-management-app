<?php

namespace App\Http\Requests\Concerns;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\Validator;

/**
 * A range of whole days for a report: `?from=` and `?to=` (Y-m-d), `from`
 * not after `to`, at most `maxRangeDays()` long. Without them it ends today
 * and starts at `defaultFrom()`, Monday of that week unless the report says
 * otherwise.
 */
trait ReadsDayRange
{
    /**
     * @return array<string, mixed>
     */
    protected function rangeRules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
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
            } elseif ((int) round($from->diffInDays($to)) + 1 > $this->maxRangeDays()) {
                $validator->errors()->add('to', 'Choose a range of at most '.$this->maxRangeDays().' days.');
            }
        });
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $to = $this->filled('to') ? CarbonImmutable::parse($this->string('to')->value())->startOfDay() : CarbonImmutable::today();
        $from = $this->filled('from') ? CarbonImmutable::parse($this->string('from')->value())->startOfDay() : $this->defaultFrom($to);

        return [$from, $to];
    }

    protected function defaultFrom(CarbonImmutable $to): CarbonImmutable
    {
        return $to->startOfWeek(CarbonInterface::MONDAY);
    }

    protected function maxRangeDays(): int
    {
        return 92;
    }
}
