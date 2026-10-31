<?php

namespace App\Http\Requests\Work\Concerns;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\Validator;

/**
 * A range of whole days for the work reports: `from` and `to`
 * (YYYY-MM-DD), this week (Monday to today) unless given, at most
 * MAX_DAYS long, `from` not after `to`.
 */
trait ReadsWorkRange
{
    public const MAX_DAYS = 92;

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
        $from = $this->filled('from') ? CarbonImmutable::parse($this->string('from')->value())->startOfDay() : $to->startOfWeek(CarbonInterface::MONDAY);

        return [$from, $to];
    }
}
