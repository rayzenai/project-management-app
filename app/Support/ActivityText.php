<?php

namespace App\Support;

use App\Models\ProjectNote;
use App\Models\Task;
use App\Services\ProjectActivityRecorder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How a change reads in a task's activity: in people's words, never the
 * stored keys or timestamps. "Status: Not Started → In Progress",
 * "Deadline: none → Nov 2, 2026", "Priority: Medium → High",
 * `Note added (Meeting): "…"`. Dates are the office's (app timezone). The observers
 * write their rows with it, and the 2026_10_31_190000 migration reworded the
 * rows written before.
 */
final class ActivityText
{
    public static function status(?string $from, ?string $to): string
    {
        return 'Status: '.Task::labelForStatus($from).' → '.Task::labelForStatus($to);
    }

    public static function priority(?string $from, ?string $to): string
    {
        return self::change('Priority', $from === null ? null : ucfirst($from), $to === null ? null : ucfirst($to));
    }

    public static function noteAdded(?string $type, string $body): string
    {
        $kind = $type === null || $type === 'general' ? '' : ' ('.(ProjectNote::TYPES[$type] ?? ucfirst(str_replace('_', ' ', $type))).')';

        return 'Note added'.$kind.': "'.ProjectActivityRecorder::truncate($body, 60).'"';
    }

    public static function change(string $label, mixed $from, mixed $to): string
    {
        return $label.': '.self::value($from).' → '.self::value($to);
    }

    /**
     * One value as a person reads it: a date as "Nov 2, 2026", nothing as
     * "none", text cut to 100 characters.
     */
    public static function value(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->setTimezone((string) config('app.timezone'))->format('M j, Y');
        }

        $text = trim(is_scalar($value) ? (string) $value : '');

        // A date or timestamp as the database or JSON keeps it (2026-11-02,
        // 2026-11-02 00:00:00, 2026-10-29T18:15:00.000000Z).
        if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/', $text) === 1) {
            return CarbonImmutable::parse($text)->setTimezone((string) config('app.timezone'))->format('M j, Y');
        }

        return $text === '' ? 'none' : ProjectActivityRecorder::truncate($text, 100);
    }
}
