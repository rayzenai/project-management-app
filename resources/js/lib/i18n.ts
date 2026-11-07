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

/** BCP 47 tags for Intl: Nepali digits and month names come with `ne`. */
const INTL: Record<Locale, string> = { en: 'en-GB', ne: 'ne-NP' };

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
    return value.toLocaleString(INTL[locale()]);
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

/** A form chosen by count, `:count` filled: tc(':count file|:count files', 3). */
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
    const relative = new Intl.RelativeTimeFormat(INTL.ne, {
        numeric: 'always',
    });

    if (minutes < 1) {
        return t('just now');
    }

    if (minutes < 60) {
        return relative.format(-minutes, 'minute');
    }

    if (hours < 24) {
        return relative.format(-hours, 'hour');
    }

    if (days < 7) {
        return relative.format(-days, 'day');
    }

    if (days < 35) {
        return relative.format(-Math.floor(days / 7), 'week');
    }

    if (days < 365) {
        return relative.format(-Math.max(1, Math.floor(days / 30)), 'month');
    }

    return relative.format(-Math.floor(days / 365), 'year');
}

/** A date as the language writes it, for Intl's options. */
export function formatDay(
    value: Date | string,
    options: Intl.DateTimeFormatOptions,
): string {
    const date = typeof value === 'string' ? new Date(value) : value;

    return new Intl.DateTimeFormat(INTL[locale()], {
        timeZone: 'Asia/Kathmandu',
        ...options,
    }).format(date);
}
