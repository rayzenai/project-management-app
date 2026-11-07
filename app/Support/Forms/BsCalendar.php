<?php

namespace App\Support\Forms;

use Carbon\CarbonImmutable;

/**
 * Bikram Sambat dates, read from resources/data/bs-calendar.json (the AD date
 * of 1 Baisakh and each month's length for every year it covers), the same
 * table resources/js/lib/forms/bsCalendar.ts reads, so the browser and the
 * server never disagree about a date. Dates are `YYYY-MM-DD` strings.
 */
final class BsCalendar
{
    /** @var array{min: int, max: int, years: array<int, array{start: string, months: list<int>}>}|null */
    private static ?array $data = null;

    /** @return array{min: int, max: int, years: array<int, array{start: string, months: list<int>}>} */
    private static function data(): array
    {
        if (self::$data === null) {
            /** @var array{min: int, max: int, years: array<int, array{start: string, months: list<int>}>} $data */
            $data = json_decode((string) file_get_contents(resource_path('data/bs-calendar.json')), true, 512, JSON_THROW_ON_ERROR);
            self::$data = $data;
        }

        return self::$data;
    }

    /**
     * The first and last BS years the table covers.
     *
     * @return array{int, int}
     */
    public static function range(): array
    {
        return [self::data()['min'], self::data()['max']];
    }

    /** @return array{int, int, int}|null year, month (1-12), day */
    private static function parts(string $date): ?array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    /** The AD date of a BS date, or null when it isn't one the table has. */
    public static function toAd(string $bs): ?string
    {
        $parts = self::parts($bs);
        $year = $parts === null ? null : (self::data()['years'][$parts[0]] ?? null);

        if ($parts === null || $year === null) {
            return null;
        }

        [, $month, $day] = $parts;

        if ($month < 1 || $month > 12 || $day < 1 || $day > $year['months'][$month - 1]) {
            return null;
        }

        $offset = array_sum(array_slice($year['months'], 0, $month - 1)) + $day - 1;

        return CarbonImmutable::parse($year['start'])->addDays($offset)->toDateString();
    }

    /** The BS date of an AD date, or null outside the table. */
    public static function fromAd(string $ad): ?string
    {
        $parts = self::parts($ad);

        if ($parts === null || ! checkdate($parts[1], $parts[2], $parts[0])) {
            return null;
        }

        $date = CarbonImmutable::create($parts[0], $parts[1], $parts[2]);

        foreach (array_reverse(self::data()['years'], true) as $bsYear => $year) {
            $start = CarbonImmutable::parse($year['start']);

            if ($date->lt($start)) {
                continue;
            }

            $offset = (int) $start->diffInDays($date);

            foreach ($year['months'] as $index => $length) {
                if ($offset < $length) {
                    return sprintf('%04d-%02d-%02d', (int) $bsYear, $index + 1, $offset + 1);
                }

                $offset -= $length;
            }

            return null;
        }

        return null;
    }
}
