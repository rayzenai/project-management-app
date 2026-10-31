<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Validation\Validator;

/**
 * An organization's working days and hours, sent whole: ISO weekdays (at
 * least one), the day's start before its end (HH:MM), seasons that change
 * the hours between two MM-DD dates (which may wrap the year end) and the
 * grace before "late".
 */
class UpdateAttendanceSettingsRequest extends ManageAttendanceRequest
{
    private const MONTH_DAY = '/^(\d{2})-(\d{2})$/';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'workdays' => ['required', 'array', 'min:1', 'max:7'],
            'workdays.*' => ['integer', 'between:1,7', 'distinct'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'seasons' => ['present', 'array', 'max:12'],
            'seasons.*.name' => ['required', 'string', 'max:60'],
            'seasons.*.from' => ['required', 'string', 'regex:'.self::MONTH_DAY],
            'seasons.*.to' => ['required', 'string', 'regex:'.self::MONTH_DAY],
            'seasons.*.start' => ['required', 'date_format:H:i'],
            'seasons.*.end' => ['required', 'date_format:H:i'],
            'grace_minutes' => ['required', 'integer', 'between:0,120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'workdays.required' => 'Pick at least one working day.',
            'workdays.min' => 'Pick at least one working day.',
            'seasons.*.from.regex' => 'Use MM-DD, like 11-02.',
            'seasons.*.to.regex' => 'Use MM-DD, like 01-29.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $errors = $validator->errors();

            if (! $errors->hasAny(['start_time', 'end_time']) && $this->string('start_time')->value() >= $this->string('end_time')->value()) {
                $errors->add('end_time', 'The day must end after it starts.');
            }

            foreach ((array) $this->input('seasons', []) as $i => $season) {
                if (! is_array($season)) {
                    continue;
                }

                foreach (['from', 'to'] as $edge) {
                    if (! $errors->has("seasons.{$i}.{$edge}") && ! self::isMonthDay((string) ($season[$edge] ?? ''))) {
                        $errors->add("seasons.{$i}.{$edge}", 'That date does not exist.');
                    }
                }

                if (! $errors->hasAny(["seasons.{$i}.start", "seasons.{$i}.end"]) && (string) ($season['start'] ?? '') >= (string) ($season['end'] ?? '')) {
                    $errors->add("seasons.{$i}.end", 'The day must end after it starts.');
                }
            }
        }];
    }

    /**
     * The settings as the service takes them.
     *
     * @return array{workdays: list<int>, start_time: string, end_time: string, seasons: list<array{name: string, from: string, to: string, start: string, end: string}>, grace_minutes: int}
     */
    public function settings(): array
    {
        /** @var array{workdays: list<int|string>, start_time: string, end_time: string, seasons: list<array{name: string, from: string, to: string, start: string, end: string}>, grace_minutes: int|string} $valid */
        $valid = $this->validated();
        $workdays = array_map('intval', $valid['workdays']);
        sort($workdays);

        return [
            'workdays' => $workdays,
            'start_time' => $valid['start_time'],
            'end_time' => $valid['end_time'],
            'seasons' => array_map(fn (array $season): array => [
                'name' => trim($season['name']),
                'from' => $season['from'],
                'to' => $season['to'],
                'start' => $season['start'],
                'end' => $season['end'],
            ], $valid['seasons']),
            'grace_minutes' => (int) $valid['grace_minutes'],
        ];
    }

    /** MM-DD naming a real day of some year (02-29 included). */
    private static function isMonthDay(string $value): bool
    {
        return preg_match(self::MONTH_DAY, $value, $m) === 1 && checkdate((int) $m[1], (int) $m[2], 2024);
    }
}
