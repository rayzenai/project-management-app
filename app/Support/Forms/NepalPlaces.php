<?php

namespace App\Support\Forms;

/**
 * Nepal's provinces, districts and local levels with their ward counts,
 * read from resources/data/nepal-places.json (from @rayzenai/nepali-geo-map,
 * MIT). A district's id starts with its province's ("P1.D01"), a local
 * level's with its district's ("P1.D01.L01"). The place question's answer is
 * `{province, district, local, ward}`, filled down to the question's depth.
 */
final class NepalPlaces
{
    public const DEPTHS = ['province', 'district', 'local', 'ward'];

    /** @var array{provinces: array<string, array{id: string, en: string, ne: string}>, districts: array<string, array{id: string, en: string, ne: string}>, locals: array<string, array{id: string, en: string, ne: string, wards: int}>}|null */
    private static ?array $data = null;

    /** @return array{provinces: array<string, array{id: string, en: string, ne: string}>, districts: array<string, array{id: string, en: string, ne: string}>, locals: array<string, array{id: string, en: string, ne: string, wards: int}>} */
    private static function data(): array
    {
        if (self::$data === null) {
            /** @var array{provinces: list<array{id: string, en: string, ne: string}>, districts: list<array{id: string, en: string, ne: string}>, locals: list<array{id: string, en: string, ne: string, wards: int}>} $raw */
            $raw = json_decode((string) file_get_contents(resource_path('data/nepal-places.json')), true, 512, JSON_THROW_ON_ERROR);
            self::$data = [
                'provinces' => array_column($raw['provinces'], null, 'id'),
                'districts' => array_column($raw['districts'], null, 'id'),
                'locals' => array_column($raw['locals'], null, 'id'),
            ];
        }

        return self::$data;
    }

    /**
     * The answer kept for a place question, or null when it isn't one: every
     * level down to the depth, each inside the one above, the ward within
     * the local level's count. Levels below the depth are dropped.
     *
     * @return array{province: string, district?: string, local?: string, ward?: int}|null
     */
    public static function clean(mixed $value, string $depth): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $levels = array_search($depth, self::DEPTHS, true);
        $levels = $levels === false ? 0 : $levels;
        $province = is_string($value['province'] ?? null) ? $value['province'] : '';

        if (! isset(self::data()['provinces'][$province])) {
            return null;
        }

        $place = ['province' => $province];

        if ($levels >= 1) {
            $district = is_string($value['district'] ?? null) ? $value['district'] : '';

            if (! isset(self::data()['districts'][$district]) || ! str_starts_with($district, $province.'.')) {
                return null;
            }

            $place['district'] = $district;
        }

        if ($levels >= 2) {
            $local = is_string($value['local'] ?? null) ? $value['local'] : '';

            if (! isset(self::data()['locals'][$local]) || ! str_starts_with($local, $place['district'].'.')) {
                return null;
            }

            $place['local'] = $local;
        }

        if ($levels >= 3) {
            $ward = filter_var($value['ward'] ?? null, FILTER_VALIDATE_INT);
            $wards = self::data()['locals'][$place['local']]['wards'];

            if (! is_int($ward) || $ward < 1 || $ward > $wards) {
                return null;
            }

            $place['ward'] = $ward;
        }

        return $place;
    }

    /**
     * "Ward 5, Bhojpur Municipality, Bhojpur, Koshi", in English or Nepali.
     *
     * @param  array<string, mixed>  $place
     */
    public static function label(array $place, string $language = 'en'): string
    {
        $data = self::data();
        $name = fn (?array $row): ?string => $row === null ? null : (string) ($row[$language === 'ne' ? 'ne' : 'en']);
        $parts = array_filter([
            isset($place['ward']) ? ($language === 'ne' ? 'वडा नं. '.$place['ward'] : 'Ward '.$place['ward']) : null,
            $name($data['locals'][(string) ($place['local'] ?? '')] ?? null),
            $name($data['districts'][(string) ($place['district'] ?? '')] ?? null),
            $name($data['provinces'][(string) ($place['province'] ?? '')] ?? null),
        ]);

        return implode(', ', $parts);
    }
}
