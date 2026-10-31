<?php

namespace App\Http\Requests\Concerns;

use Carbon\CarbonImmutable;

/**
 * One day of a report: `?date=` (Y-m-d), today unless given.
 */
trait ReadsDay
{
    /**
     * @return array<string, mixed>
     */
    protected function dayRules(): array
    {
        return ['date' => ['nullable', 'date_format:Y-m-d']];
    }

    public function day(): CarbonImmutable
    {
        return $this->filled('date')
            ? CarbonImmutable::parse($this->string('date')->value())->startOfDay()
            : CarbonImmutable::today();
    }
}
