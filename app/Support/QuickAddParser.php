<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Tokenizer for natural-language quick add. The grammar is intentionally
 * small and position-independent:
 *
 *   #project-token   @assignee-token   !low|!medium|!high|!urgent|!p1..!p4
 *   today · tomorrow · weekday names · YYYY-MM-DD · "jun 20" · "20 jun"
 *
 * `parse()` is pure tokenization — it never touches the database. The caller
 * resolves project/assignee tokens and then calls `strip()` with only the
 * tokens it actually consumed, so unresolvable tokens stay in the title and
 * no typed text is silently lost. The client mirrors these rules for
 * display-only highlighting; this class is the authority.
 *
 * @phpstan-type Token array{type: 'project'|'assignee'|'priority'|'date', raw: string, value: string, offset: int}
 */
class QuickAddParser
{
    private const PRIORITY_SHORTHAND = ['p1' => 'urgent', 'p2' => 'high', 'p3' => 'medium', 'p4' => 'low'];

    private const WEEKDAYS = [
        'mon' => Carbon::MONDAY,
        'tue' => Carbon::TUESDAY,
        'wed' => Carbon::WEDNESDAY,
        'thu' => Carbon::THURSDAY,
        'fri' => Carbon::FRIDAY,
        'sat' => Carbon::SATURDAY,
        'sun' => Carbon::SUNDAY,
    ];

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    private const MONTH_RE = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

    private const WEEKDAY_RE = 'mon(?:day)?|tue(?:s(?:day)?)?|wed(?:nesday)?|thu(?:r(?:s(?:day)?)?)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?';

    /**
     * @return list<Token>
     */
    public static function parse(string $input): array
    {
        $rules = [
            'project' => '/(^|\s)(#[\w\-]+)/u',
            'assignee' => '/(^|\s)(@[\w\-]+)/u',
            'priority' => '/(^|\s)(!(?:low|medium|high|urgent|p[1-4]))(?=\s|$)/iu',
            'date' => '/(^|\s)(today|tomorrow|'.self::WEEKDAY_RE.'|\d{4}-\d{2}-\d{2}|(?:'.self::MONTH_RE.')\s+\d{1,2}|\d{1,2}\s+(?:'.self::MONTH_RE.'))(?=\s|$)/iu',
        ];

        $tokens = [];
        $claimed = [];

        foreach ($rules as $type => $pattern) {
            preg_match_all($pattern, $input, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[2] as $match) {
                [$raw, $offset] = $match;

                $overlaps = false;
                foreach ($claimed as [$start, $end]) {
                    if ($offset < $end && $offset + strlen($raw) > $start) {
                        $overlaps = true;
                        break;
                    }
                }
                if ($overlaps) {
                    continue;
                }

                $value = self::valueFor($type, $raw);
                if ($value === null) {
                    continue;
                }

                $claimed[] = [$offset, $offset + strlen($raw)];
                $tokens[] = ['type' => $type, 'raw' => $raw, 'value' => $value, 'offset' => $offset];
            }
        }

        usort($tokens, fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return $tokens;
    }

    /**
     * Remove the given tokens from the input and tidy the whitespace.
     *
     * @param  list<Token>  $tokens
     */
    public static function strip(string $input, array $tokens): string
    {
        usort($tokens, fn (array $a, array $b): int => $b['offset'] <=> $a['offset']);

        foreach ($tokens as $token) {
            $input = substr_replace($input, '', $token['offset'], strlen($token['raw']));
        }

        return trim((string) preg_replace('/\s+/u', ' ', $input));
    }

    private static function valueFor(string $type, string $raw): ?string
    {
        return match ($type) {
            'project', 'assignee' => substr($raw, 1),
            'priority' => self::priorityValue(substr($raw, 1)),
            'date' => self::dateValue($raw),
            default => null,
        };
    }

    private static function priorityValue(string $word): string
    {
        $word = strtolower($word);

        return self::PRIORITY_SHORTHAND[$word] ?? $word;
    }

    private static function dateValue(string $raw): ?string
    {
        $word = strtolower(trim($raw));
        $today = Carbon::today();

        if ($word === 'today') {
            return $today->toDateString();
        }

        if ($word === 'tomorrow') {
            return $today->copy()->addDay()->toDateString();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $word)) {
            return $word;
        }

        $prefix = substr($word, 0, 3);
        if (isset(self::WEEKDAYS[$prefix]) && ! preg_match('/\d/', $word)) {
            $diff = (self::WEEKDAYS[$prefix] - $today->dayOfWeek + 7) % 7;

            return $today->copy()->addDays($diff)->toDateString();
        }

        if (preg_match('/^([a-z]+)\s+(\d{1,2})$/', $word, $m)) {
            return self::monthDay($m[1], (int) $m[2], $today);
        }

        if (preg_match('/^(\d{1,2})\s+([a-z]+)$/', $word, $m)) {
            return self::monthDay($m[2], (int) $m[1], $today);
        }

        return null;
    }

    private static function monthDay(string $monthWord, int $day, Carbon $today): ?string
    {
        $month = self::MONTHS[substr($monthWord, 0, 3)] ?? null;
        if ($month === null || $day < 1 || $day > 31) {
            return null;
        }

        if (! checkdate($month, $day, $today->year)) {
            return null;
        }

        $date = Carbon::create($today->year, $month, $day);
        if ($date->lt($today)) {
            $date = Carbon::create($today->year + 1, $month, $day);
        }

        return $date->toDateString();
    }
}
