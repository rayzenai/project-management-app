// Display-only mirror of the server-side QuickAddParser grammar
// (packages/project-management/src/Support/QuickAddParser.php). The client
// never resolves tokens — `#procure` highlights even if no project matches;
// the server is the authority. Pure TS, no runes, unit-testable.

export type SegmentType =
    'plain' | 'project' | 'assignee' | 'priority' | 'date';
export type Segment = { text: string; type: SegmentType };

type TokenType = Exclude<SegmentType, 'plain'>;

const MONTHS =
    'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
const WEEKDAYS =
    'mon(?:day)?|tue(?:s(?:day)?)?|wed(?:nesday)?|thu(?:r(?:s(?:day)?)?)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?';

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
            `(?<=^|\\s)(?:today|tomorrow|${WEEKDAYS}` +
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
