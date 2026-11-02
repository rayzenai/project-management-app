// Display-only mirror of the server-side QuickAddParser grammar
// (app/Support/QuickAddParser.php). The client never resolves tokens for
// real — `#procure` highlights even if no project matches; the server is the
// authority. `tokenDate` reads a typed date only so a chip can show it.
// Pure TS, no runes, unit-testable.

import { isoDay, localDay, shiftDay } from './dates';

export type SegmentType =
    'plain' | 'project' | 'assignee' | 'priority' | 'date';
export type Segment = { text: string; type: SegmentType };

type TokenType = Exclude<SegmentType, 'plain'>;

const MONTHS =
    'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
const WEEKDAYS =
    'mon(?:day)?|tue(?:s(?:day)?)?|wed(?:nesday)?|thu(?:r(?:s(?:day)?)?)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?';

/** Days from today, by their Nepali word. */
const NEPALI_DAYS: Record<string, number> = {
    आज: 0,
    भोलि: 1,
    पर्सि: 2,
    पर्सी: 2,
};

/** Sunday is 0, as Date#getDay counts. */
const NEPALI_WEEKDAYS: Record<string, number> = {
    आइतबार: 0,
    सोमबार: 1,
    मंगलबार: 2,
    बुधबार: 3,
    बिहिबार: 4,
    बिहीबार: 4,
    शुक्रबार: 5,
    शनिबार: 6,
};

// Literal words: a Devanagari vowel sign is not a word character.
const NEPALI = [
    ...Object.keys(NEPALI_DAYS),
    ...Object.keys(NEPALI_WEEKDAYS),
].join('|');

const RULES: { type: TokenType; re: RegExp }[] = [
    { type: 'project', re: /(?<=^|\s)#[\w-]+/g },
    { type: 'assignee', re: /(?<=^|\s)@[\w-]+/g },
    {
        type: 'priority',
        re: /(?<=^|\s)!(?:low|medium|high|urgent|p[1-4])(?=\s|$)/gi,
    },
    {
        type: 'date',
        re: new RegExp(
            `(?<=^|\\s)(?:today|tomorrow|${WEEKDAYS}|${NEPALI}` +
                `|\\d{4}-\\d{2}-\\d{2}` +
                `|(?:${MONTHS})\\s+\\d{1,2}|\\d{1,2}\\s+(?:${MONTHS}))(?=\\s|$)`,
            'gi',
        ),
    },
];

/**
 * Run all rules in order, collect non-overlapping matches (earlier rule wins
 * on overlap, same as QuickAddParser's claimed-range walk), sort by position,
 * and emit plain segments between token segments.
 */
export function tokenize(input: string): Segment[] {
    const matches: { start: number; end: number; type: TokenType }[] = [];

    for (const rule of RULES) {
        rule.re.lastIndex = 0;

        for (const m of input.matchAll(rule.re)) {
            const start = m.index ?? 0;
            const end = start + m[0].length;

            if (matches.some((t) => start < t.end && end > t.start)) {
                continue;
            }

            matches.push({ start, end, type: rule.type });
        }
    }

    matches.sort((a, b) => a.start - b.start);

    const segments: Segment[] = [];
    let cursor = 0;

    for (const t of matches) {
        if (t.start > cursor) {
            segments.push({
                text: input.slice(cursor, t.start),
                type: 'plain',
            });
        }

        segments.push({ text: input.slice(t.start, t.end), type: t.type });
        cursor = t.end;
    }

    if (cursor < input.length) {
        segments.push({ text: input.slice(cursor), type: 'plain' });
    }

    return segments;
}

/**
 * The day the first typed date names, Y-m-d, read as QuickAddParser reads
 * it: a weekday that is today means today, a month and day already past
 * means next year. `today` is the office's, Y-m-d.
 */
export function tokenDate(input: string, today: string): string | null {
    for (const segment of tokenize(input)) {
        const day =
            segment.type === 'date' ? dateValue(segment.text, today) : null;

        if (day !== null) {
            return day;
        }
    }

    return null;
}

const SHORT_WEEKDAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
const SHORT_MONTHS = [
    'jan',
    'feb',
    'mar',
    'apr',
    'may',
    'jun',
    'jul',
    'aug',
    'sep',
    'oct',
    'nov',
    'dec',
];

function dateValue(raw: string, today: string): string | null {
    const word = raw.trim().toLowerCase();

    if (word === 'today') {
        return today;
    }

    if (word === 'tomorrow') {
        return shiftDay(today, 1);
    }

    if (NEPALI_DAYS[word] !== undefined) {
        return shiftDay(today, NEPALI_DAYS[word]);
    }

    if (NEPALI_WEEKDAYS[word] !== undefined) {
        return weekday(NEPALI_WEEKDAYS[word], today);
    }

    if (/^\d{4}-\d{2}-\d{2}$/.test(word)) {
        return word;
    }

    const day = SHORT_WEEKDAYS.indexOf(word.slice(0, 3));

    if (day !== -1 && !/\d/.test(word)) {
        return weekday(day, today);
    }

    const monthFirst = /^([a-z]+)\s+(\d{1,2})$/.exec(word);
    const dayFirst = /^(\d{1,2})\s+([a-z]+)$/.exec(word);

    if (monthFirst) {
        return monthDay(monthFirst[1], Number(monthFirst[2]), today);
    }

    if (dayFirst) {
        return monthDay(dayFirst[2], Number(dayFirst[1]), today);
    }

    return null;
}

/** The next such weekday; today when it is one. */
function weekday(target: number, today: string): string {
    return shiftDay(today, (target - localDay(today).getDay() + 7) % 7);
}

function monthDay(
    monthWord: string,
    day: number,
    today: string,
): string | null {
    const month = SHORT_MONTHS.indexOf(monthWord.slice(0, 3));
    const year = localDay(today).getFullYear();
    const date = new Date(year, month, day);

    if (month === -1 || date.getMonth() !== month || date.getDate() !== day) {
        return null;
    }

    const iso = isoDay(date);

    return iso < today ? isoDay(new Date(year + 1, month, day)) : iso;
}
