import {
    AlignLeft,
    AtSign,
    Calendar,
    CheckSquare,
    CircleDot,
    FileText,
    FileUp,
    Gauge,
    Hash,
    Heading,
    Image,
    Link,
    MapPin,
    Minus,
    Phone,
    Repeat,
    ShieldCheck,
    Text,
    ToggleLeft,
    Type,
    Video,
} from '@lucide/svelte';
import { newId } from '../../../lib/forms/ids';
import type {
    Block,
    ContentType,
    QuestionType,
} from '../../../lib/forms/types';

/** The builder's kinds of block: their names, icons and how a new one starts. */
export const KINDS: Record<
    ContentType | QuestionType,
    { name: string; icon: typeof Text; hint: string }
> = {
    short_text: {
        name: 'Short answer',
        icon: Type,
        hint: 'A line of text, with checks such as Nepali only',
    },
    paragraph: {
        name: 'Paragraph',
        icon: AlignLeft,
        hint: 'Several lines of text',
    },
    number: { name: 'Number', icon: Hash, hint: 'With a lowest and highest' },
    email: { name: 'Email', icon: AtSign, hint: 'An email address' },
    phone: {
        name: 'Mobile number',
        icon: Phone,
        hint: 'A Nepali mobile number',
    },
    url: { name: 'Link', icon: Link, hint: 'A web address' },
    date: { name: 'Date', icon: Calendar, hint: 'BS or AD, with an age check' },
    choice: {
        name: 'One choice',
        icon: CircleDot,
        hint: 'Radio buttons or a searchable list',
    },
    choices: { name: 'Several choices', icon: CheckSquare, hint: 'Checkboxes' },
    yes_no: { name: 'Yes or no', icon: ToggleLeft, hint: 'Two buttons' },
    scale: { name: 'Scale', icon: Gauge, hint: '1 to 5 or 1 to 10' },
    file: {
        name: 'File upload',
        icon: FileUp,
        hint: 'PDF, pictures, Word or Excel',
    },
    place: {
        name: 'Place in Nepal',
        icon: MapPin,
        hint: 'Province, district, local level, ward',
    },
    group: {
        name: 'Repeating group',
        icon: Repeat,
        hint: 'Add another: degrees, trainings, items',
    },
    declaration: {
        name: 'Declaration',
        icon: ShieldCheck,
        hint: 'A box to tick',
    },
    heading: { name: 'Heading', icon: Heading, hint: 'A title inside a step' },
    text: {
        name: 'Text',
        icon: Text,
        hint: 'Instructions, with bold, lists and links',
    },
    image: { name: 'Picture', icon: Image, hint: 'An image to show' },
    video: { name: 'Video', icon: Video, hint: 'A YouTube or Vimeo link' },
    download: {
        name: 'File to download',
        icon: FileText,
        hint: 'A circular or a guide',
    },
    divider: { name: 'Divider', icon: Minus, hint: 'A line between parts' },
};

export const QUESTION_MENU: QuestionType[] = [
    'short_text',
    'paragraph',
    'choice',
    'choices',
    'yes_no',
    'number',
    'date',
    'email',
    'phone',
    'url',
    'scale',
    'file',
    'place',
    'group',
    'declaration',
];

export const GROUP_MENU: QuestionType[] = [
    'short_text',
    'paragraph',
    'choice',
    'choices',
    'yes_no',
    'number',
    'date',
    'email',
    'phone',
    'url',
    'scale',
    'file',
    'place',
];

export const CONTENT_MENU: ContentType[] = [
    'heading',
    'text',
    'image',
    'video',
    'download',
    'divider',
];

export function kindName(type: string): string {
    return (KINDS as Record<string, { name: string }>)[type]?.name ?? type;
}

const empty = () => ({ en: '', ne: '' });

/** A new block of a kind, with sensible starting rules. */
export function newBlock(type: ContentType | QuestionType): Block {
    const id = newId('q');

    if (
        ['heading', 'text', 'image', 'video', 'download', 'divider'].includes(
            type,
        )
    ) {
        switch (type) {
            case 'heading':
                return { id, type, show_if: null, text: empty() };
            case 'text':
                return { id, type, show_if: null, doc: { en: null, ne: null } };
            case 'image':
                return { id, type, show_if: null, file: null, alt: empty() };
            case 'video':
                return { id, type, show_if: null, url: '' };
            case 'download':
                return { id, type, show_if: null, file: null, label: empty() };
            default:
                return { id, type, show_if: null };
        }
    }

    const block: Block = {
        id,
        type,
        show_if: null,
        label: empty(),
        help: empty(),
        required: false,
        rules: {},
    };

    if (type === 'choice' || type === 'choices') {
        block.options = [
            { id: newId('o'), label: { en: 'Option 1', ne: '' } },
            { id: newId('o'), label: { en: 'Option 2', ne: '' } },
        ];
    }

    if (type === 'date') {
        block.rules = { calendar: 'bs' };
    }

    if (type === 'scale') {
        block.rules = { max: 5, min_label: empty(), max_label: empty() };
    }

    if (type === 'file') {
        block.rules = { kinds: ['pdf', 'image'], max_mb: 10, max_files: 1 };
    }

    if (type === 'place') {
        block.rules = { depth: 'local' };
    }

    if (type === 'group') {
        block.blocks = [];
        block.rules = { add_label: empty() };
    }

    if (type === 'declaration') {
        block.required = true;
        block.text = {
            en: 'I declare that the information I have given is true.',
            ne: 'मैले दिएको विवरण सत्य हो भनी उद्घोष गर्दछु।',
        };
    }

    return block;
}
