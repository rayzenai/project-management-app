<script lang="ts">
    import { FileSpreadsheet, FileText, Presentation } from '@lucide/svelte';
    import type { DocumentFileType } from '../../lib/types';

    /**
     * A document kind's icon on its tint: Word blue, Excel green,
     * PowerPoint orange, so a row's kind reads at a glance. Decorative: the
     * row or tile says the kind in words.
     */
    let {
        type,
        size = 'md',
    }: {
        type: DocumentFileType;
        /** sm 28px (rows on a phone), md 32px (rows), lg 36px (the start tiles). */
        size?: 'sm' | 'md' | 'lg';
    } = $props();

    const ICONS = {
        docx: FileText,
        xlsx: FileSpreadsheet,
        pptx: Presentation,
    } as const;

    const TONES = {
        docx: 'bg-doc-docx-soft text-doc-docx',
        xlsx: 'bg-doc-xlsx-soft text-doc-xlsx',
        pptx: 'bg-doc-pptx-soft text-doc-pptx',
    } as const;

    const BOXES = {
        sm: 'h-7 w-7',
        md: 'h-8 w-8',
        lg: 'h-9 w-9',
    } as const;

    const Icon = $derived(ICONS[type]);
</script>

<span
    class={`inline-flex shrink-0 items-center justify-center rounded-md ${TONES[type]} ${BOXES[size]}`}
    aria-hidden="true"
>
    <Icon class={size === 'lg' ? 'h-5 w-5' : 'h-4 w-4'} />
</span>
