<?php

use App\Support\BikramSambat;
use Carbon\CarbonImmutable;

it('converts known dates', function (string $date, array $expected) {
    expect(BikramSambat::fromGregorian(CarbonImmutable::parse($date)))->toBe($expected);
})->with([
    'new year 2083' => ['2026-04-14', ['year' => 2083, 'month' => 1, 'day' => 1]],
    'today' => ['2026-11-02', ['year' => 2083, 'month' => 7, 'day' => 16]],
    'a few days on' => ['2026-11-06', ['year' => 2083, 'month' => 7, 'day' => 20]],
    'first day of the table' => ['2013-04-14', ['year' => 2070, 'month' => 1, 'day' => 1]],
    'last day of 2082' => ['2026-04-13', ['year' => 2082, 'month' => 12, 'day' => 30]],
]);

it('formats the short date', function () {
    expect(BikramSambat::short(CarbonImmutable::parse('2026-11-02')))->toBe('कार्तिक १६');
});

it('returns null outside the table', function (string $date) {
    expect(BikramSambat::fromGregorian(CarbonImmutable::parse($date)))->toBeNull()
        ->and(BikramSambat::short(CarbonImmutable::parse($date)))->toBeNull();
})->with(['2010-01-01', '2050-01-01']);

it('writes Devanagari digits', function () {
    expect(BikramSambat::digits(2083))->toBe('२०८३')
        ->and(BikramSambat::digits('09'))->toBe('०९');
});

it('names the twelve months', function () {
    expect(BikramSambat::monthName(1))->toBe('बैशाख')->and(BikramSambat::monthName(12))->toBe('चैत्र');
});
