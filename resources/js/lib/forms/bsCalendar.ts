import calendar from '../../../data/bs-calendar.json';

/**
 * Bikram Sambat dates from resources/data/bs-calendar.json, the table
 * App\Support\Forms\BsCalendar reads too, so the page and the server agree
 * on every date. Dates are `YYYY-MM-DD` strings.
 */

type Year = { start: string; months: number[] };

const years = calendar.years as Record<string, Year>;

export const BS_FIRST_YEAR = calendar.min;
export const BS_LAST_YEAR = calendar.max;

export const BS_MONTHS = [
    'Baisakh',
    'Jestha',
    'Asar',
    'Shrawan',
    'Bhadra',
    'Ashwin',
    'Kartik',
    'Mangsir',
    'Poush',
    'Magh',
    'Falgun',
    'Chaitra',
];

export const BS_MONTHS_NE = [
    'बैशाख',
    'जेठ',
    'असार',
    'श्रावण',
    'भदौ',
    'आश्विन',
    'कार्तिक',
    'मंसिर',
    'पुष',
    'माघ',
    'फाल्गुन',
    'चैत्र',
];

const DAY = 86_400_000;

function utc(ad: string): number {
    const [y, m, d] = ad.split('-').map(Number);

    return Date.UTC(y, m - 1, d);
}

function iso(ms: number): string {
    return new Date(ms).toISOString().slice(0, 10);
}

/** Days in a BS month (1-12), or 0 outside the table. */
export function bsMonthLength(year: number, month: number): number {
    return years[String(year)]?.months[month - 1] ?? 0;
}

export function bsToAd(bs: string): string | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(bs);
    const year = match ? years[match[1]] : undefined;

    if (!match || !year) {
        return null;
    }

    const month = Number(match[2]);
    const day = Number(match[3]);

    if (month < 1 || month > 12 || day < 1 || day > year.months[month - 1]) {
        return null;
    }

    const offset =
        year.months.slice(0, month - 1).reduce((sum, n) => sum + n, 0) +
        day -
        1;

    return iso(utc(year.start) + offset * DAY);
}

export function adToBs(ad: string): string | null {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(ad)) {
        return null;
    }

    const target = utc(ad);

    for (let y = BS_LAST_YEAR; y >= BS_FIRST_YEAR; y--) {
        const year = years[String(y)];

        if (!year || target < utc(year.start)) {
            continue;
        }

        let offset = Math.round((target - utc(year.start)) / DAY);

        for (let m = 0; m < 12; m++) {
            if (offset < year.months[m]) {
                return `${y}-${String(m + 1).padStart(2, '0')}-${String(offset + 1).padStart(2, '0')}`;
            }

            offset -= year.months[m];
        }

        return null;
    }

    return null;
}

/** Today's date in Nepal, as AD. */
export function todayAd(): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Kathmandu',
    }).format(new Date());
}
