<?php

namespace App\Support\Forms;

use Carbon\CarbonImmutable;
use Closure;

/**
 * What a respondent's answers mean against a form's questions (FormSchema):
 * which steps and blocks show, what a draft keeps, and, on Submit, the
 * answers to keep and what is wrong with the rest. This is the one truth;
 * resources/js/lib/forms/answers.ts gives the same quick feedback in the
 * browser. A hidden question is never required, and an answer left in a
 * hidden question is dropped, so a response never answers a question it
 * didn't show.
 */
final class FormAnswers
{
    /** The most characters any text answer may have, whatever its own limit. */
    public const TEXT_MAX = 20000;

    /**
     * The ids of the steps and blocks that show, in order. A condition looks
     * only at questions that show themselves.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $answers
     * @return array{steps: array<string, true>, blocks: array<string, true>}
     */
    public static function visible(array $schema, array $answers): array
    {
        $shown = ['steps' => [], 'blocks' => []];
        $seen = [];

        foreach (FormSchema::steps($schema) as $step) {
            if (! self::matches($step['show_if'] ?? null, $seen)) {
                continue;
            }

            $shown['steps'][(string) $step['id']] = true;

            foreach (FormSchema::blocks($step) as $block) {
                if (! self::matches($block['show_if'] ?? null, $seen)) {
                    continue;
                }

                $shown['blocks'][(string) $block['id']] = true;

                if (FormSchema::isQuestion((string) $block['type']) && array_key_exists((string) $block['id'], $answers)) {
                    $seen[(string) $block['id']] = $answers[(string) $block['id']];
                }
            }
        }

        return $shown;
    }

    /**
     * @param  array<string, mixed>  $answers  answers of the questions shown so far
     */
    public static function matches(mixed $condition, array $answers): bool
    {
        if (! is_array($condition) || ! is_array($condition['conditions'] ?? null) || $condition['conditions'] === []) {
            return true;
        }

        $results = array_map(fn (mixed $test): bool => is_array($test) && self::test($test, $answers[(string) ($test['question'] ?? '')] ?? null), $condition['conditions']);

        return ($condition['match'] ?? 'all') === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    /** @param  array<string, mixed>  $test */
    private static function test(array $test, mixed $value): bool
    {
        $target = $test['value'] ?? null;

        return match ($test['op'] ?? null) {
            'answered' => self::filled($value),
            'not_answered' => ! self::filled($value),
            'equals' => self::same($value, $target),
            'not_equals' => ! self::same($value, $target),
            'includes' => is_array($value) && in_array($target, $value, true),
            default => true,
        };
    }

    private static function same(mixed $value, mixed $target): bool
    {
        if (is_bool($target)) {
            return $value === $target;
        }

        if (is_int($target) || is_float($target)) {
            return is_numeric($value) && (float) $value === (float) $target;
        }

        return is_string($target) && is_string($value) && mb_strtolower(trim($value)) === mb_strtolower(trim($target));
    }

    /** Whether a question has an answer: false is one (a "no"), an empty string or list is not. */
    public static function filled(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return array_filter($value, fn (mixed $part): bool => self::filled($part)) !== [];
        }

        return true;
    }

    /**
     * What a draft keeps: answers to the form's questions, each of a shape
     * its question can hold, cut to size. Nothing is checked, so whatever a
     * person typed, half an email address included, is never lost.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function draft(array $schema, mixed $answers): array
    {
        if (! is_array($answers)) {
            return [];
        }

        $kept = [];

        foreach (FormSchema::questions($schema) as $id => $question) {
            if (array_key_exists($id, $answers)) {
                $value = self::draftValue($question, $answers[$id]);

                if ($value !== null) {
                    $kept[$id] = $value;
                }
            }
        }

        return $kept;
    }

    /** @param  array<string, mixed>  $question */
    private static function draftValue(array $question, mixed $value): mixed
    {
        return match ((string) $question['type']) {
            'number' => is_int($value) || is_float($value) ? $value : (is_string($value) ? mb_substr($value, 0, 50) : null),
            'yes_no', 'declaration' => is_bool($value) ? $value : null,
            'scale' => is_int($value) ? $value : null,
            'choice' => is_string($value) ? mb_substr($value, 0, 40) : null,
            'choices' => is_array($value) ? array_values(array_filter(array_slice($value, 0, FormSchema::MAX_OPTIONS), 'is_string')) : null,
            'file' => is_array($value) ? array_values(array_filter(array_slice($value, 0, FormSchema::MAX_FILES), 'is_int')) : null,
            'date' => is_array($value) ? array_filter(['ad' => is_string($value['ad'] ?? null) ? mb_substr($value['ad'], 0, 10) : null, 'bs' => is_string($value['bs'] ?? null) ? mb_substr($value['bs'], 0, 10) : null]) : null,
            'place' => is_array($value) ? array_filter([
                'province' => is_string($value['province'] ?? null) ? mb_substr($value['province'], 0, 20) : null,
                'district' => is_string($value['district'] ?? null) ? mb_substr($value['district'], 0, 20) : null,
                'local' => is_string($value['local'] ?? null) ? mb_substr($value['local'], 0, 20) : null,
                'ward' => is_int($value['ward'] ?? null) ? $value['ward'] : null,
            ]) : null,
            'group' => is_array($value) ? array_values(array_map(
                fn (mixed $entry): array => is_array($entry) ? array_filter(
                    array_map(fn (array $child): mixed => self::draftValue($child, $entry[(string) $child['id']] ?? null), array_column(FormSchema::blocks($question), null, 'id')),
                    fn (mixed $part): bool => $part !== null,
                ) : [],
                array_slice($value, 0, FormSchema::MAX_GROUP_ENTRIES),
            )) : null,
            default => is_string($value) ? mb_substr($value, 0, self::TEXT_MAX) : null,
        };
    }

    /**
     * Submit: the answers to keep (hidden questions dropped, every value in
     * its stored shape) and what is wrong, by question id, or
     * `{group}.{entry}.{question}` inside a repeating group.
     *
     * @param  array<string, mixed>  $schema
     * @param  Closure(list<int>, string): list<int>|null  $ownFiles  of these file ids, those this person uploaded to this question (null: none are)
     * @return array{answers: array<string, mixed>, errors: array<string, string>}
     */
    public static function check(array $schema, mixed $answers, ?Closure $ownFiles = null, ?CarbonImmutable $today = null): array
    {
        $answers = is_array($answers) ? $answers : [];
        $visible = self::visible($schema, $answers)['blocks'];
        $today ??= CarbonImmutable::today();
        $kept = [];
        $errors = [];

        foreach (FormSchema::questions($schema) as $id => $question) {
            if (! isset($visible[$id])) {
                continue;
            }

            if ($question['type'] === 'group') {
                [$value, $groupErrors] = self::checkGroup($question, $answers[$id] ?? null, $ownFiles, $today);
                $errors += $groupErrors;
            } else {
                [$value, $error] = self::checkValue($question, $answers[$id] ?? null, $ownFiles, $today);

                if ($error !== null) {
                    $errors[$id] = $error;
                }
            }

            if ($value !== null) {
                $kept[$id] = $value;
            }
        }

        return ['answers' => $kept, 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  Closure(list<int>, string): list<int>|null  $ownFiles
     * @return array{0: list<array<string, mixed>>|null, 1: array<string, string>}
     */
    private static function checkGroup(array $group, mixed $value, ?Closure $ownFiles, CarbonImmutable $today): array
    {
        $id = (string) $group['id'];
        $rules = is_array($group['rules'] ?? null) ? $group['rules'] : [];
        $entries = array_values(array_filter(is_array($value) ? $value : [], fn (mixed $entry): bool => is_array($entry) && self::filled($entry)));
        $errors = [];
        $min = max((int) ($rules['min'] ?? 0), ($group['required'] ?? false) ? 1 : 0);
        $max = min((int) ($rules['max'] ?? FormSchema::MAX_GROUP_ENTRIES), FormSchema::MAX_GROUP_ENTRIES);

        if (count($entries) < $min) {
            $errors[$id] = $min === 1 ? 'Add at least one.' : "Add at least {$min}.";
        } elseif (count($entries) > $max) {
            $errors[$id] = "Add no more than {$max}.";
        }

        $kept = [];

        foreach (array_slice($entries, 0, $max) as $i => $entry) {
            $clean = [];

            foreach (FormSchema::blocks($group) as $child) {
                [$childValue, $error] = self::checkValue($child, $entry[(string) $child['id']] ?? null, $ownFiles, $today);

                if ($error !== null) {
                    $errors["{$id}.{$i}.{$child['id']}"] = $error;
                } elseif ($childValue !== null) {
                    $clean[(string) $child['id']] = $childValue;
                }
            }

            $kept[] = $clean;
        }

        return [$kept === [] ? null : $kept, $errors];
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  Closure(list<int>, string): list<int>|null  $ownFiles
     * @return array{0: mixed, 1: string|null}
     */
    private static function checkValue(array $question, mixed $value, ?Closure $ownFiles, CarbonImmutable $today): array
    {
        $type = (string) $question['type'];
        $rules = is_array($question['rules'] ?? null) ? $question['rules'] : [];

        if ($type === 'declaration') {
            return $value === true ? [true, null] : [null, 'Tick this to go on.'];
        }

        if (! self::filled($value)) {
            return [null, ($question['required'] ?? false) ? self::missing($type) : null];
        }

        return match ($type) {
            'short_text' => self::text($value, $rules, 500, false),
            'paragraph' => self::text($value, $rules, 5000, true),
            'number' => self::number($value, $rules),
            'email' => is_string($value) && filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false
                ? [mb_strtolower(trim($value)), null]
                : [null, 'Give an email address, like name@example.gov.np.'],
            'phone' => self::phone($value),
            'url' => is_string($value) && filter_var(trim($value), FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url(trim($value), PHP_URL_SCHEME)), ['http', 'https'], true)
                ? [trim($value), null]
                : [null, 'Give a link that starts with https://'],
            'date' => self::date($value, $rules, $today),
            'choice' => is_string($value) && in_array($value, array_column(is_array($question['options'] ?? null) ? $question['options'] : [], 'id'), true)
                ? [$value, null]
                : [null, 'Choose one of the answers.'],
            'choices' => self::choices($value, $question, $rules),
            'yes_no' => is_bool($value) ? [$value, null] : [null, 'Answer yes or no.'],
            'scale' => is_int($value) && $value >= 1 && $value <= (int) ($rules['max'] ?? 5)
                ? [$value, null]
                : [null, 'Choose a number from 1 to '.(int) ($rules['max'] ?? 5).'.'],
            'file' => self::files($value, $question, $rules, $ownFiles),
            'place' => ($place = NepalPlaces::clean($value, (string) ($rules['depth'] ?? 'local'))) !== null
                ? [$place, null]
                : [null, 'Choose the place down to the '.self::depthName((string) ($rules['depth'] ?? 'local')).'.'],
            default => [null, null],
        };
    }

    private static function missing(string $type): string
    {
        return match ($type) {
            'choice', 'choices', 'yes_no', 'scale' => 'Choose an answer.',
            'file' => 'Add a file.',
            'place' => 'Choose a place.',
            'date' => 'Give a date.',
            default => 'Answer this question.',
        };
    }

    private static function depthName(string $depth): string
    {
        return match ($depth) {
            'province' => 'province',
            'district' => 'district',
            'ward' => 'ward',
            default => 'local level',
        };
    }

    /** Devanagari digits read as digits. */
    private static function digits(string $value): string
    {
        return strtr($value, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{0: string|null, 1: string|null}
     */
    private static function text(mixed $value, array $rules, int $defaultMax, bool $lines): array
    {
        if (! is_string($value)) {
            return [null, 'Answer this question.'];
        }

        $text = trim($lines ? str_replace("\r\n", "\n", $value) : preg_replace('/\s+/u', ' ', $value) ?? $value);
        $length = mb_strlen($text);
        $max = (int) ($rules['max_length'] ?? $defaultMax);
        $min = (int) ($rules['min_length'] ?? 0);

        if ($length > $max) {
            return [null, "Keep this to {$max} characters (it has {$length})."];
        }

        if ($length < $min) {
            return [null, "Write at least {$min} characters."];
        }

        $error = match ($rules['format'] ?? 'any') {
            'devanagari' => preg_match('/^[\p{Devanagari}\p{Zs}\p{P}\p{N}]+$/u', $text) === 1 && preg_match('/\p{Devanagari}/u', $text) === 1
                ? null : 'Type this in Nepali (Unicode), not in Preeti or English letters.',
            'latin' => preg_match('/^[\p{Latin}\p{Zs}\p{P}\p{N}]+$/u', $text) === 1 && preg_match('/\p{Latin}/u', $text) === 1
                ? null : 'Use English letters.',
            'digits' => preg_match('/^[0-9०-९]+$/u', $text) === 1 ? null : 'Use digits only.',
            'pattern' => FormSchema::patternWorks((string) ($rules['pattern'] ?? '')) && @preg_match(FormSchema::regex((string) $rules['pattern']), $text) === 1
                ? null : "This isn't in the form it should be in.",
            default => null,
        };

        return $error === null ? [$text, null] : [null, $error];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{0: int|float|null, 1: string|null}
     */
    private static function number(mixed $value, array $rules): array
    {
        $raw = is_string($value) ? str_replace([',', ' '], '', self::digits(trim($value))) : $value;

        if (! is_numeric($raw)) {
            return [null, 'Give a number.'];
        }

        $number = $raw + 0;

        if (($rules['integer'] ?? false) && floor((float) $number) !== (float) $number) {
            return [null, 'Give a whole number.'];
        }

        if (isset($rules['min']) && $number < $rules['min']) {
            return [null, "Give a number of at least {$rules['min']}."];
        }

        if (isset($rules['max']) && $number > $rules['max']) {
            return [null, "Give a number no more than {$rules['max']}."];
        }

        return [$number, null];
    }

    /** @return array{0: string|null, 1: string|null} */
    private static function phone(mixed $value): array
    {
        $digits = is_string($value) ? preg_replace('/[\s\-().]/', '', self::digits(trim($value))) ?? '' : '';
        $digits = preg_replace('/^(?:\+?977)/', '', $digits) ?? $digits;

        return preg_match('/^9[678]\d{8}$/', $digits) === 1
            ? [$digits, null]
            : [null, 'Give a Nepali mobile number of 10 digits, like 9841234567.'];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{0: array{ad: string, bs: string}|null, 1: string|null}
     */
    private static function date(mixed $value, array $rules, CarbonImmutable $today): array
    {
        $calendar = ($rules['calendar'] ?? 'bs') === 'ad' ? 'ad' : 'bs';
        $typed = is_array($value) && is_string($value[$calendar] ?? null) ? $value[$calendar] : '';
        $ad = $calendar === 'bs' ? BsCalendar::toAd($typed) : ($typed !== '' && BsCalendar::fromAd($typed) !== null ? $typed : null);
        [$first, $last] = BsCalendar::range();

        if ($ad === null) {
            return [null, $calendar === 'bs' ? "Give a real date between {$first} and {$last} BS." : 'Give a real date.'];
        }

        $bs = (string) BsCalendar::fromAd($ad);
        $show = fn (string $adDate): string => $calendar === 'bs' ? (string) BsCalendar::fromAd($adDate).' BS' : $adDate;

        if (isset($rules['earliest']) && $ad < $rules['earliest']) {
            return [null, 'Give a date on or after '.$show((string) $rules['earliest']).'.'];
        }

        if (isset($rules['latest']) && $ad > $rules['latest']) {
            return [null, 'Give a date on or before '.$show((string) $rules['latest']).'.'];
        }

        if (isset($rules['age_min']) || isset($rules['age_max'])) {
            $on = isset($rules['age_on']) ? CarbonImmutable::parse((string) $rules['age_on']) : $today;
            $age = (int) CarbonImmutable::parse($ad)->diffInYears($on);
            $low = (int) ($rules['age_min'] ?? 0);
            $high = (int) ($rules['age_max'] ?? 150);

            if ($age < $low || $age > $high) {
                $range = isset($rules['age_min'], $rules['age_max']) ? "between {$low} and {$high}" : (isset($rules['age_min']) ? "at least {$low}" : "no more than {$high}");

                return [null, "The age on {$show($on->toDateString())} must be {$range} years."];
            }
        }

        return [['ad' => $ad, 'bs' => $bs], null];
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $rules
     * @return array{0: list<string>|null, 1: string|null}
     */
    private static function choices(mixed $value, array $question, array $rules): array
    {
        $options = array_column(is_array($question['options'] ?? null) ? $question['options'] : [], 'id');
        $chosen = is_array($value) ? array_values(array_unique(array_filter($value, 'is_string'))) : [];

        if ($chosen === [] || array_diff($chosen, $options) !== []) {
            return [null, 'Choose from the answers given.'];
        }

        $chosen = array_values(array_intersect($options, $chosen));

        if (isset($rules['min']) && count($chosen) < $rules['min']) {
            return [null, "Choose at least {$rules['min']}."];
        }

        if (isset($rules['max']) && count($chosen) > $rules['max']) {
            return [null, "Choose no more than {$rules['max']}."];
        }

        return [$chosen, null];
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $rules
     * @param  Closure(list<int>, string): list<int>|null  $ownFiles
     * @return array{0: list<int>|null, 1: string|null}
     */
    private static function files(mixed $value, array $question, array $rules, ?Closure $ownFiles): array
    {
        $ids = is_array($value) ? array_values(array_unique(array_filter($value, 'is_int'))) : [];
        $max = (int) ($rules['max_files'] ?? 1);

        if (count($ids) > $max) {
            return [null, $max === 1 ? 'Add one file only.' : "Add no more than {$max} files."];
        }

        $own = $ownFiles === null ? [] : $ownFiles($ids, (string) $question['id']);

        if ($ids === [] || array_diff($ids, $own) !== []) {
            return [null, 'A file is missing. Add it again.'];
        }

        return [$ids, null];
    }
}
