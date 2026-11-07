/**
 * A form's questions (the schema App\Support\Forms\FormSchema keeps) and
 * the shapes the Forms pages receive. See
 * docs/superpowers/plans/2026-11-07-forms.md for the schema.
 */

export type Language = 'en' | 'ne';

export type Translations = { en: string; ne: string };

export type ConditionOp =
    'equals' | 'not_equals' | 'includes' | 'answered' | 'not_answered';

export interface ConditionTest {
    question: string;
    op: ConditionOp;
    value?: string | number | boolean;
}

export interface Condition {
    match: 'all' | 'any';
    conditions: ConditionTest[];
}

export type ContentType =
    'heading' | 'text' | 'image' | 'video' | 'download' | 'divider';

export type QuestionType =
    | 'short_text'
    | 'paragraph'
    | 'number'
    | 'email'
    | 'phone'
    | 'url'
    | 'date'
    | 'choice'
    | 'choices'
    | 'yes_no'
    | 'scale'
    | 'file'
    | 'place'
    | 'group'
    | 'declaration';

export interface Option {
    id: string;
    label: Translations;
}

/** TipTap's JSON document. */
export interface RichDoc {
    type: 'doc';
    content?: RichNode[];
}

export interface RichNode {
    type: string;
    text?: string;
    content?: RichNode[];
    marks?: { type: string; attrs?: { href?: string } }[];
}

export interface Rules {
    format?: 'any' | 'devanagari' | 'latin' | 'digits' | 'pattern';
    pattern?: string;
    min_length?: number;
    max_length?: number;
    min?: number;
    max?: number;
    integer?: boolean;
    calendar?: 'bs' | 'ad';
    earliest?: string;
    latest?: string;
    age_min?: number;
    age_max?: number;
    age_on?: string;
    display?: 'radio' | 'dropdown';
    min_label?: Translations;
    max_label?: Translations;
    kinds?: ('pdf' | 'image' | 'word' | 'excel')[];
    max_mb?: number;
    max_files?: number;
    depth?: 'province' | 'district' | 'local' | 'ward';
    add_label?: Translations;
}

export interface Block {
    id: string;
    type: ContentType | QuestionType;
    show_if?: Condition | null;
    // Questions
    label?: Translations;
    help?: Translations;
    required?: boolean;
    rules?: Rules;
    options?: Option[];
    blocks?: Block[];
    // Content and declarations
    text?: Translations;
    doc?: { en: RichDoc | null; ne: RichDoc | null };
    file?: number | null;
    alt?: Translations;
    url?: string;
}

export interface Step {
    id: string;
    title: Translations;
    show_if: Condition | null;
    blocks: Block[];
}

export interface Schema {
    languages: Language[];
    steps: Step[];
}

export type Answers = Record<string, unknown>;

export interface FileRef {
    id: number;
    name: string;
    size: number;
    image: boolean;
    url: string;
}

export interface PersonRef {
    id: number;
    name: string;
    designation: string | null;
}

export type FormPhase = 'draft' | 'scheduled' | 'open' | 'closed';

export type FormAccess = 'edit' | 'responses';

export interface FormItem {
    id: number;
    code: string;
    title: string;
    phase: FormPhase;
    opens_at: string | null;
    closes_at: string | null;
    answering: 'person' | 'organization';
    /** Null for someone of another organization: they learn the office, not its project. */
    project: { slug: string; title: string; label: string } | null;
    organization: string | null;
    access: FormAccess | null;
    responses_count: number | null;
    mine: {
        reference: string;
        submitted_at: string;
        updated_at: string | null;
    } | null;
    updated_at: string | null;
    link: string;
}

export interface FormTemplate {
    id: string;
    name: string;
    description: string;
}

export interface Problem {
    message: string;
    block: string | null;
    level: 'error' | 'warning';
}

export interface Stage {
    id: string;
    name: string;
}

export interface ProjectForms {
    forms: FormItem[];
    can_create: boolean;
    templates: FormTemplate[];
}

export const QUESTION_TYPES: QuestionType[] = [
    'short_text',
    'paragraph',
    'number',
    'email',
    'phone',
    'url',
    'date',
    'choice',
    'choices',
    'yes_no',
    'scale',
    'file',
    'place',
    'group',
    'declaration',
];

export function isQuestion(type: string): type is QuestionType {
    return (QUESTION_TYPES as string[]).includes(type);
}
