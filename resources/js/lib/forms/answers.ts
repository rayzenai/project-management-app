import { bsToAd } from './bsCalendar';
import type { Answers, Block, Condition, ConditionTest, Schema } from './types';
import { isQuestion } from './types';

/**
 * The page's copy of App\Support\Forms\FormAnswers: which steps and blocks
 * show for these answers, and quick checks before Next and Submit. The
 * server's check on Submit is the one that counts; these say the same
 * things sooner.
 */

export function filled(value: unknown): boolean {
    if (value === null || value === undefined) {
        return false;
    }

    if (typeof value === 'string') {
        return value.trim() !== '';
    }

    if (Array.isArray(value)) {
        return value.some((part) => filled(part));
    }

    if (typeof value === 'object') {
        return Object.values(value as Record<string, unknown>).some((part) =>
            filled(part),
        );
    }

    return true;
}

function same(value: unknown, target: unknown): boolean {
    if (typeof target === 'boolean') {
        return value === target;
    }

    if (typeof target === 'number') {
        return value !== '' && value !== null && Number(value) === target;
    }

    return (
        typeof target === 'string' &&
        typeof value === 'string' &&
        value.trim().toLowerCase() === target.trim().toLowerCase()
    );
}

function test(t: ConditionTest, value: unknown): boolean {
    switch (t.op) {
        case 'answered':
            return filled(value);
        case 'not_answered':
            return !filled(value);
        case 'equals':
            return same(value, t.value);
        case 'not_equals':
            return !same(value, t.value);
        case 'includes':
            return Array.isArray(value) && value.includes(t.value);
        default:
            return true;
    }
}

export function matches(
    condition: Condition | null | undefined,
    answers: Answers,
): boolean {
    if (!condition || condition.conditions.length === 0) {
        return true;
    }

    const results = condition.conditions.map((t) =>
        test(t, answers[t.question]),
    );

    return condition.match === 'any'
        ? results.includes(true)
        : !results.includes(false);
}

/** The ids of the steps and blocks that show, in order. */
export function visible(
    schema: Schema,
    answers: Answers,
): { steps: Set<string>; blocks: Set<string> } {
    const steps = new Set<string>();
    const blocks = new Set<string>();
    const seen: Answers = {};

    for (const step of schema.steps) {
        if (!matches(step.show_if, seen)) {
            continue;
        }

        steps.add(step.id);

        for (const block of step.blocks) {
            if (!matches(block.show_if, seen)) {
                continue;
            }

            blocks.add(block.id);

            if (isQuestion(block.type) && block.id in answers) {
                seen[block.id] = answers[block.id];
            }
        }
    }

    return { steps, blocks };
}

const DIGITS: Record<string, string> = {
    '०': '0',
    '१': '1',
    '२': '2',
    '३': '3',
    '४': '4',
    '५': '5',
    '६': '6',
    '७': '7',
    '८': '8',
    '९': '9',
};

function latinDigits(value: string): string {
    return value.replace(/[०-९]/g, (d) => DIGITS[d] ?? d);
}

function missing(type: string): string {
    switch (type) {
        case 'choice':
        case 'choices':
        case 'yes_no':
        case 'scale':
            return 'Choose an answer.';
        case 'file':
            return 'Add a file.';
        case 'place':
            return 'Choose a place.';
        case 'date':
            return 'Give a date.';
        default:
            return 'Answer this question.';
    }
}

/** What is wrong with one answer, or null. Not every rule: the server has the rest. */
export function problem(question: Block, value: unknown): string | null {
    const rules = question.rules ?? {};

    if (question.type === 'declaration') {
        return value === true ? null : 'Tick this to go on.';
    }

    if (!filled(value)) {
        return question.required ? missing(question.type) : null;
    }

    if (question.type === 'group') {
        return null;
    }

    if (
        (question.type === 'short_text' || question.type === 'paragraph') &&
        typeof value === 'string'
    ) {
        const text = value.trim();
        const max =
            rules.max_length ?? (question.type === 'short_text' ? 500 : 5000);

        if ([...text].length > max) {
            return `Keep this to ${max} characters (it has ${[...text].length}).`;
        }

        if (rules.min_length && [...text].length < rules.min_length) {
            return `Write at least ${rules.min_length} characters.`;
        }

        if (
            rules.format === 'devanagari' &&
            (!/^[\p{Script=Devanagari}\p{Zs}\p{P}\p{N}]+$/u.test(text) ||
                !/\p{Script=Devanagari}/u.test(text))
        ) {
            return 'Type this in Nepali (Unicode), not in Preeti or English letters.';
        }

        if (
            rules.format === 'latin' &&
            (!/^[\p{Script=Latin}\p{Zs}\p{P}\p{N}]+$/u.test(text) ||
                !/\p{Script=Latin}/u.test(text))
        ) {
            return 'Use English letters.';
        }

        if (rules.format === 'digits' && !/^[0-9०-९]+$/.test(text)) {
            return 'Use digits only.';
        }
    }

    if (question.type === 'number') {
        const raw = latinDigits(String(value).trim()).replace(/[,\s]/g, '');
        const number = Number(raw);

        if (raw === '' || Number.isNaN(number)) {
            return 'Give a number.';
        }

        if (rules.integer && !Number.isInteger(number)) {
            return 'Give a whole number.';
        }

        if (rules.min !== undefined && number < rules.min) {
            return `Give a number of at least ${rules.min}.`;
        }

        if (rules.max !== undefined && number > rules.max) {
            return `Give a number no more than ${rules.max}.`;
        }
    }

    if (
        question.type === 'email' &&
        !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value).trim())
    ) {
        return 'Give an email address, like name@example.gov.np.';
    }

    if (question.type === 'phone') {
        const digits = latinDigits(String(value))
            .replace(/[\s\-().]/g, '')
            .replace(/^\+?977/, '');

        if (!/^9[678]\d{8}$/.test(digits)) {
            return 'Give a Nepali mobile number of 10 digits, like 9841234567.';
        }
    }

    if (
        question.type === 'url' &&
        !/^https?:\/\/\S+\.\S+/.test(String(value))
    ) {
        return 'Give a link that starts with https://';
    }

    if (question.type === 'date') {
        const date = value as { bs?: string; ad?: string };
        const ad =
            (rules.calendar ?? 'bs') === 'bs'
                ? bsToAd(date.bs ?? '')
                : (date.ad ?? null);

        if (!ad) {
            return 'Give a real date.';
        }
    }

    if (question.type === 'choices' && Array.isArray(value)) {
        if (rules.min && value.length < rules.min) {
            return `Choose at least ${rules.min}.`;
        }

        if (rules.max && value.length > rules.max) {
            return `Choose no more than ${rules.max}.`;
        }
    }

    if (question.type === 'place') {
        const place = value as Record<string, unknown>;
        const depth = rules.depth ?? 'local';
        const needed = ['province', 'district', 'local', 'ward'].slice(
            0,
            ['province', 'district', 'local', 'ward'].indexOf(depth) + 1,
        );

        if (needed.some((level) => !filled(place[level]))) {
            return `Choose the place down to the ${depth === 'local' ? 'local level' : depth}.`;
        }
    }

    return null;
}

/** Problems with a step's shown questions, by question id (or group entry path). */
export function stepProblems(
    blocks: Block[],
    shown: Set<string>,
    answers: Answers,
): Record<string, string> {
    const errors: Record<string, string> = {};

    for (const block of blocks) {
        if (!shown.has(block.id) || !isQuestion(block.type)) {
            continue;
        }

        if (block.type === 'group') {
            const entries = (
                Array.isArray(answers[block.id]) ? answers[block.id] : []
            ) as Record<string, unknown>[];
            const kept = entries.filter((entry) => filled(entry));
            const min = Math.max(block.rules?.min ?? 0, block.required ? 1 : 0);

            if (kept.length < min) {
                errors[block.id] =
                    min === 1 ? 'Add at least one.' : `Add at least ${min}.`;
            }

            entries.forEach((entry, i) => {
                if (!filled(entry)) {
                    return;
                }

                for (const child of block.blocks ?? []) {
                    const wrong = problem(child, entry[child.id]);

                    if (wrong) {
                        errors[`${block.id}.${i}.${child.id}`] = wrong;
                    }
                }
            });

            continue;
        }

        const wrong = problem(block, answers[block.id]);

        if (wrong) {
            errors[block.id] = wrong;
        }
    }

    return errors;
}

/** Words in a language, or the other one when they are missing there. */
export function say(
    translations: { en?: string; ne?: string } | undefined | null,
    language: 'en' | 'ne',
): string {
    const wanted = translations?.[language]?.trim() ?? '';

    if (wanted !== '') {
        return wanted;
    }

    return translations?.en?.trim() || translations?.ne?.trim() || '';
}
