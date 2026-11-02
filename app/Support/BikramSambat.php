<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Gregorian to Bikram Sambat (Nepal's calendar), for BS 2070 to 2100 only.
 * The month lengths and each year's Baisakh 1 (as an AD date) are copied
 * from patro's `App\Services\BikramSambat\DataSource` (rayzen/patro/api),
 * the same table that app ships. Outside the table every answer is null.
 */
final class BikramSambat
{
    private const FIRST_YEAR = 2070;

    private const LAST_YEAR = 2100;

    private const MONTHS = ['बैशाख', 'जेठ', 'असार', 'श्रावण', 'भदौ', 'आश्विन', 'कार्तिक', 'मंसिर', 'पुष', 'माघ', 'फाल्गुन', 'चैत्र'];

    private const DIGITS = ['०', '१', '२', '३', '४', '५', '६', '७', '८', '९'];

    /** @var array<int, list<int>> */
    private const MONTH_LENGTHS = [
        2070 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
        2071 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2072 => [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2073 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2074 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2075 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2076 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2077 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2078 => [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2079 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2080 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2081 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2082 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2083 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2084 => [31, 31, 32, 31, 31, 30, 30, 30, 29, 30, 30, 30],
        2085 => [31, 32, 31, 32, 30, 31, 30, 30, 29, 30, 30, 30],
        2086 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2087 => [31, 31, 32, 31, 31, 31, 30, 30, 29, 30, 30, 30],
        2088 => [30, 31, 32, 32, 30, 31, 30, 30, 29, 30, 30, 30],
        2089 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2090 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2091 => [31, 31, 32, 31, 31, 31, 30, 30, 29, 30, 30, 30],
        2092 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2093 => [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2094 => [31, 31, 32, 31, 31, 30, 30, 30, 29, 30, 30, 30],
        2095 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 30, 30, 30],
        2096 => [30, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2097 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2098 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2099 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2100 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
    ];

    /** @var array<int, string> */
    private const YEAR_STARTS = [
        2070 => '2013-04-14',
        2071 => '2014-04-14',
        2072 => '2015-04-14',
        2073 => '2016-04-13',
        2074 => '2017-04-14',
        2075 => '2018-04-14',
        2076 => '2019-04-14',
        2077 => '2020-04-13',
        2078 => '2021-04-14',
        2079 => '2022-04-14',
        2080 => '2023-04-14',
        2081 => '2024-04-13',
        2082 => '2025-04-14',
        2083 => '2026-04-14',
        2084 => '2027-04-14',
        2085 => '2028-04-13',
        2086 => '2029-04-14',
        2087 => '2030-04-14',
        2088 => '2031-04-15',
        2089 => '2032-04-14',
        2090 => '2033-04-14',
        2091 => '2034-04-14',
        2092 => '2035-04-15',
        2093 => '2036-04-15',
        2094 => '2037-04-15',
        2095 => '2038-04-15',
        2096 => '2039-04-16',
        2097 => '2040-04-14',
        2098 => '2041-04-15',
        2099 => '2042-04-15',
        2100 => '2043-04-15',
    ];

    /** @return array{year: int, month: int, day: int}|null */
    public static function fromGregorian(CarbonInterface $date): ?array
    {
        $day = $date->toDateString();

        if ($day < self::YEAR_STARTS[self::FIRST_YEAR]) {
            return null;
        }

        $year = self::FIRST_YEAR;

        while ($year < self::LAST_YEAR && $day >= self::YEAR_STARTS[$year + 1]) {
            $year++;
        }

        $remaining = (int) CarbonImmutable::parse(self::YEAR_STARTS[$year])->diffInDays(CarbonImmutable::parse($day));

        foreach (self::MONTH_LENGTHS[$year] as $index => $length) {
            if ($remaining < $length) {
                return ['year' => $year, 'month' => $index + 1, 'day' => $remaining + 1];
            }

            $remaining -= $length;
        }

        return null; // past the end of BS 2100
    }

    public static function monthName(int $month): string
    {
        return self::MONTHS[$month - 1];
    }

    public static function digits(int|string $value): string
    {
        return strtr((string) $value, array_combine(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], self::DIGITS));
    }

    /** "कार्तिक १६", or null outside the table. */
    public static function short(CarbonInterface $date): ?string
    {
        $bs = self::fromGregorian($date);

        return $bs === null ? null : self::monthName($bs['month']).' '.self::digits($bs['day']);
    }
}
