import { say } from './answers';
import type { Places } from './places';
import type { Block, FileRef } from './types';

/**
 * An answer in words for reading it back (the review page, a response):
 * the page's twin of App\Support\Forms\FormAnswerText.
 */
export function answerText(
    question: Block,
    value: unknown,
    language: 'en' | 'ne',
    files: Record<number, FileRef> = {},
    places: Places | null = null,
): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const option = (id: unknown) =>
        say(question.options?.find((o) => o.id === id)?.label, language) ||
        String(id);

    switch (question.type) {
        case 'choice':
            return option(value);
        case 'choices':
            return Array.isArray(value) ? value.map(option).join(', ') : '';
        case 'yes_no':
            return value === true ? 'Yes' : value === false ? 'No' : '';
        case 'declaration':
            return value === true ? 'Yes' : '';
        case 'date': {
            const date = value as { bs?: string; ad?: string };

            return (question.rules?.calendar ?? 'bs') === 'ad'
                ? (date.ad ?? '')
                : [
                      date.bs ? `${date.bs} BS` : '',
                      date.ad ? `(${date.ad})` : '',
                  ]
                      .filter(Boolean)
                      .join(' ');
        }
        case 'place': {
            const place = value as Record<string, string | number>;
            const name = (
                list: { id: string; en: string; ne: string }[] | undefined,
                id: unknown,
            ) => {
                const found = list?.find((p) => p.id === id);

                return found
                    ? found[language]
                    : typeof id === 'string'
                      ? id
                      : '';
            };

            return [
                place.ward ? `Ward ${place.ward}` : '',
                name(places?.locals, place.local),
                name(places?.districts, place.district),
                name(places?.provinces, place.province),
            ]
                .filter(Boolean)
                .join(', ');
        }
        case 'file':
            return Array.isArray(value)
                ? value
                      .map((id) => files[id as number]?.name ?? 'A file')
                      .join(', ')
                : '';
        case 'group':
            return Array.isArray(value)
                ? `${value.length} ${value.length === 1 ? 'entry' : 'entries'}`
                : '';
        default:
            return String(value);
    }
}
