<script lang="ts">
    import { Bold, Italic, Link2, List, ListOrdered } from '@lucide/svelte';
    import type { Editor } from '@tiptap/core';
    import { onDestroy, onMount } from 'svelte';
    import type { RichDoc } from '../../lib/forms/types';

    /**
     * The builder's editor for a text block: bold, italic, lists and links,
     * nothing else (what App\Support\Forms\FormRichText keeps). TipTap
     * loads with the builder only.
     */
    let {
        value,
        onchange,
        label,
    }: {
        value: RichDoc | null;
        onchange: (doc: RichDoc | null) => void;
        label: string;
    } = $props();

    let element = $state<HTMLDivElement | null>(null);
    let editor = $state<Editor | null>(null);
    let tick = $state(0);

    onMount(async () => {
        const [{ Editor }, { default: StarterKit }] = await Promise.all([
            import('@tiptap/core'),
            import('@tiptap/starter-kit'),
        ]);

        if (!element) {
            return;
        }

        editor = new Editor({
            element,
            extensions: [
                StarterKit.configure({
                    heading: false,
                    blockquote: false,
                    codeBlock: false,
                    code: false,
                    horizontalRule: false,
                    strike: false,
                    underline: false,
                    link: {
                        openOnClick: false,
                        protocols: ['http', 'https', 'mailto'],
                    },
                }),
            ],
            content: value ?? '',
            editorProps: {
                attributes: {
                    class: 'input min-h-24 py-2 leading-relaxed [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5 [&_a]:text-accent [&_a]:underline',
                    'aria-label': label,
                },
            },
            onUpdate: ({ editor: e }) => {
                const doc = e.getJSON() as RichDoc;
                onchange(e.isEmpty ? null : doc);
            },
            onTransaction: () => {
                tick++;
            },
        });
    });

    onDestroy(() => editor?.destroy());

    function active(name: string): boolean {
        void tick;

        return editor?.isActive(name) ?? false;
    }

    function link() {
        if (!editor) {
            return;
        }

        const current = editor.getAttributes('link').href as string | undefined;
        const url = window.prompt('Link (https://...)', current ?? 'https://');

        if (url === null) {
            return;
        }

        if (url.trim() === '' || url.trim() === 'https://') {
            editor.chain().focus().unsetLink().run();

            return;
        }

        editor
            .chain()
            .focus()
            .extendMarkRange('link')
            .setLink({ href: url.trim() })
            .run();
    }

    const tools = [
        {
            name: 'bold',
            label: 'Bold',
            icon: Bold,
            run: () => editor?.chain().focus().toggleBold().run(),
        },
        {
            name: 'italic',
            label: 'Italic',
            icon: Italic,
            run: () => editor?.chain().focus().toggleItalic().run(),
        },
        {
            name: 'bulletList',
            label: 'Bulleted list',
            icon: List,
            run: () => editor?.chain().focus().toggleBulletList().run(),
        },
        {
            name: 'orderedList',
            label: 'Numbered list',
            icon: ListOrdered,
            run: () => editor?.chain().focus().toggleOrderedList().run(),
        },
    ];
</script>

<div>
    <div class="mb-1 flex gap-0.5" role="toolbar" aria-label="Formatting">
        {#each tools as tool (tool.name)}
            <button
                type="button"
                class="btn-icon"
                class:bg-hover={active(tool.name)}
                aria-pressed={active(tool.name)}
                title={tool.label}
                aria-label={tool.label}
                onclick={tool.run}
            >
                <tool.icon class="h-3.5 w-3.5" />
            </button>
        {/each}
        <button
            type="button"
            class="btn-icon"
            class:bg-hover={active('link')}
            title="Link"
            aria-label="Link"
            onclick={link}
        >
            <Link2 class="h-3.5 w-3.5" />
        </button>
    </div>
    <div bind:this={element}></div>
</div>
