import type {
    FileRef,
    FormItem,
    FormTemplate,
    PersonRef,
    Problem,
    Schema,
    Stage,
} from '../../../lib/forms/types';
import type { ProjectChoices } from '../../../lib/types';

/** What the builder page receives (App\Queries\Forms\FormBuilderQuery), and gets back after each change. */
export interface BuilderData {
    form: FormItem & {
        status: 'draft' | 'open' | 'closed';
        respondents: 'everyone' | 'team' | 'people';
        response_limit: number | null;
        settings: {
            allow_edit: boolean;
            review_page: boolean;
            notify: boolean;
        };
        stages: Stage[];
        maker: string | null;
        published: boolean;
        answering_locked: boolean;
        can_delete: boolean;
    };
    draft: Schema;
    problems: Problem[];
    changed: boolean;
    versions: { number: number; published_at: string }[];
    files: Record<number, FileRef>;
    respondents: {
        people: PersonRef[];
        organizations: { id: number; name: string }[];
        can_add_organizations: boolean;
        organization_choices: { id: number; name: string }[];
    };
    tasks: {
        settings: {
            project_id: number;
            assignee_id: number;
            due_days: number | null;
            title_question: string | null;
            copy_questions: string[];
        } | null;
        project: { slug: string; label: string } | null;
        assignee: PersonRef | null;
        /** Where its tasks may go. */
        places: ProjectChoices;
    };
    templates: FormTemplate[];
    /** Where a copy may go. */
    places: ProjectChoices;
    limits: { max_upload_mb: number; max_steps: number; max_blocks: number };
}

/** "2026-11-10T17:00" (a datetime-local box) for an office timestamp, by Nepal's clock. */
export function toLocalInput(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Kathmandu',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(new Date(iso));
    const get = (type: string) =>
        parts.find((p) => p.type === type)?.value ?? '';

    return `${get('year')}-${get('month')}-${get('day')}T${get('hour') === '24' ? '00' : get('hour')}:${get('minute')}`;
}

/** A datetime-local box's value as an office timestamp. */
export function fromLocalInput(value: string): string | null {
    return value ? `${value}:00+05:45` : null;
}
