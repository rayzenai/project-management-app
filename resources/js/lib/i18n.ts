import { page } from '@inertiajs/svelte';
import ne from '../../../lang/ne.json';
import { formatTimeAgo } from './format';

/**
 * English and Nepali (docs/superpowers/specs/2026-11-07-languages-design.md).
 * The English text is the key, as in Laravel's JSON translations:
 * `lang/ne.json` maps it to Nepali for both the server (`__()`) and the
 * browser (`t()`), and a key with no Nepali yet shows in English. The
 * language is the shared `locale` prop, so everything that calls these
 * while rendering follows the switch at once. Never call them once at the
 * top of a script (the result would not follow the switch): call them in
 * the markup or in `$derived`.
 *
 * Placeholders are Laravel's `:name`; a number given for one is written as
 * the language writes numbers (१२ in Nepali). `tc` picks a form by count
 * from Laravel's `one|many` or `{0} none|{1} one|[2,*] many` strings.
 */
export type Locale = 'en' | 'ne';

const DICTIONARIES: Record<Locale, Record<string, string>> = {
    en: {},
    ne: ne as Record<string, string>,
};

/*
 * Nepali is written here rather than asked of the browser: many browsers
 * carry no Nepali locale data, and Intl then answers in English. Numbers
 * take the Indian (lakh) grouping and Devanagari digits; dates take the
 * Gregorian months and weekdays in Nepali.
 */
const NE_MONTHS = [
    'जनवरी',
    'फेब्रुअरी',
    'मार्च',
    'अप्रिल',
    'मे',
    'जुन',
    'जुलाई',
    'अगस्ट',
    'सेप्टेम्बर',
    'अक्टोबर',
    'नोभेम्बर',
    'डिसेम्बर',
];
const NE_WEEKDAYS = [
    'आइतबार',
    'सोमबार',
    'मङ्गलबार',
    'बुधबार',
    'बिहीबार',
    'शुक्रबार',
    'शनिबार',
];
const NE_WEEKDAYS_SHORT = [
    'आइत',
    'सोम',
    'मङ्गल',
    'बुध',
    'बिही',
    'शुक्र',
    'शनि',
];
const WEEKDAY_INDEX: Record<string, number> = {
    Sun: 0,
    Mon: 1,
    Tue: 2,
    Wed: 3,
    Thu: 4,
    Fri: 5,
    Sat: 6,
};
const TIME_ZONE = 'Asia/Kathmandu';

export function locale(): Locale {
    return (page.props as { locale?: string } | undefined)?.locale === 'ne'
        ? 'ne'
        : 'en';
}

export function isNepali(): boolean {
    return locale() === 'ne';
}

/** A number as the language writes it: "1,234" or "१,२३४". */
export function number(value: number): string {
    return locale() === 'ne'
        ? digits(value.toLocaleString('en-IN'))
        : value.toLocaleString('en-GB');
}

const DEVANAGARI_DIGITS = '०१२३४५६७८९';

/** Digits in ready-made text ("12.3 KB") as the language writes them. */
export function digits(text: string): string {
    return locale() === 'ne'
        ? text.replace(/[0-9]/g, (digit) => DEVANAGARI_DIGITS[Number(digit)])
        : text;
}

function fill(text: string, replace: Record<string, string | number>): string {
    // Longest names first, so :count never eats :count_total.
    return Object.keys(replace)
        .sort((a, b) => b.length - a.length)
        .reduce((out, name) => {
            const value = replace[name];

            return out.replaceAll(
                `:${name}`,
                typeof value === 'number' ? number(value) : value,
            );
        }, text);
}

/**
 * Marks an English text kept in data (an option's label, a link's name) as
 * a key, for the check; it returns the text unchanged. Draw it with
 * `t(option.label)`.
 */
export function msg(text: string): string {
    return text;
}

/** The text in the current language, placeholders filled. */
export function t(
    key: string,
    replace: Record<string, string | number> = {},
): string {
    return fill(DICTIONARIES[locale()][key] ?? key, replace);
}

const CONDITION = /^\s*(\{(\d+)\}|\[(\d+|\*),\s*(\d+|\*)\])\s*/;

/** Laravel's MessageSelector: an exact or ranged form first, else one|many. */
function pick(text: string, count: number): string {
    const forms = text.split('|');

    for (const form of forms) {
        const match = form.match(CONDITION);

        if (!match) {
            continue;
        }

        const exact = match[2] !== undefined && Number(match[2]) === count;
        const low = match[3] === '*' ? -Infinity : Number(match[3]);
        const high = match[4] === '*' ? Infinity : Number(match[4]);
        const inRange = match[3] !== undefined && count >= low && count <= high;

        if (exact || inRange) {
            return form.slice(match[0].length);
        }
    }

    const plain = forms.map((form) => form.replace(CONDITION, ''));

    return plain.length === 1
        ? plain[0]
        : (plain[count === 1 ? 0 : 1] ?? plain[0]);
}

/** A form chosen by count (Laravel's one|many), `:count` filled. */
export function tc(
    key: string,
    count: number,
    replace: Record<string, string | number> = {},
): string {
    return fill(pick(DICTIONARIES[locale()][key] ?? key, count), {
        count,
        ...replace,
    });
}

/** "3 hours ago" / "३ घण्टा पहिले", on the same steps as formatTimeAgo. */
export function timeAgo(
    value: string | null | undefined,
    now: Date = new Date(),
): string {
    if (locale() === 'en' || !value) {
        return formatTimeAgo(value, now);
    }

    const minutes = Math.floor(
        Math.max(0, now.getTime() - new Date(value).getTime()) / 60_000,
    );
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);

    if (minutes < 1) {
        return t('just now');
    }

    if (minutes < 60) {
        return tc(':count minute ago|:count minutes ago', minutes);
    }

    if (hours < 24) {
        return tc(':count hour ago|:count hours ago', hours);
    }

    if (days < 7) {
        return tc(':count day ago|:count days ago', days);
    }

    if (days < 35) {
        return tc(':count week ago|:count weeks ago', Math.floor(days / 7));
    }

    if (days < 365) {
        return tc(
            ':count month ago|:count months ago',
            Math.max(1, Math.floor(days / 30)),
        );
    }

    return tc(':count year ago|:count years ago', Math.floor(days / 365));
}

/**
 * A date as the language writes it, for Intl's options (in English Intl's
 * en-GB). In Nepali the weekday, the month and day, and the year, as the
 * options ask for them: "शनिबार, नोभेम्बर ७", "नोभेम्बर ७, २०२६".
 */
export function formatDay(
    value: Date | string,
    options: Intl.DateTimeFormatOptions,
): string {
    const date = typeof value === 'string' ? new Date(value) : value;

    if (locale() === 'en') {
        return new Intl.DateTimeFormat('en-GB', {
            timeZone: TIME_ZONE,
            ...options,
        }).format(date);
    }

    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-US', {
            timeZone: options.timeZone ?? TIME_ZONE,
            weekday: 'short',
            year: 'numeric',
            month: 'numeric',
            day: 'numeric',
        })
            .formatToParts(date)
            .map((part) => [part.type, part.value]),
    );
    const weekday = WEEKDAY_INDEX[parts.weekday] ?? 0;
    const month = Number(parts.month) - 1;
    const day = digits(parts.day);
    const year = digits(parts.year);

    const monthDay =
        options.month === 'numeric' || options.month === '2-digit'
            ? `${digits(String(month + 1))}/${day}`
            : options.month
              ? `${NE_MONTHS[month]} ${options.day ? day : ''}`.trim()
              : options.day
                ? day
                : '';
    const dated = [monthDay, options.year ? year : '']
        .filter(Boolean)
        .join(', ');
    const named = options.weekday
        ? options.weekday === 'long'
            ? NE_WEEKDAYS[weekday]
            : NE_WEEKDAYS_SHORT[weekday]
        : '';

    return [named, dated].filter(Boolean).join(', ');
}
