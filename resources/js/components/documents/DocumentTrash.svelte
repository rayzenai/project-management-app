<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import {
        FileSpreadsheet,
        FileText,
        Presentation,
        RotateCcw,
        Trash2,
    } from '@lucide/svelte';
    import { formatTimeAgo, plural } from '../../lib/format';
    import type { DocumentItem } from '../../lib/types';
    import Spinner from '../Spinner.svelte';

    /**
     * The trash, loaded when opened from `url` (all the viewer's, or one
     * project's). Nothing in it is ever deleted; Restore shows only where
     * the row allows it, and a frozen document opens read-only from here
     * for a super-admin.
     */
    let { url, count }: { url: string; count: number } = $props();

    const ICONS = {
        docx: FileText,
        xlsx: FileSpreadsheet,
        pptx: Presentation,
    } as const;

    let open = $state(false);
    let trashed = $state<DocumentItem[] | null>(null);
    let failed = $state(false);

    async function load() {
        failed = false;

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            trashed = ((await response.json()) as { data: DocumentItem[] })
                .data;
        } catch {
            failed = true;
        }
    }

    function toggle() {
        open = !open;

        if (open) {
            trashed = null;
            void load();
        }
    }

    function restore(document: DocumentItem) {
        router.post(
            `/workspace/documents/${document.id}/restore`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => void load(),
            },
        );
    }
</script>

{#if count > 0 || open}
    <div class="mt-4">
        <button
            type="button"
            class="btn-ghost -ml-2"
            aria-expanded={open}
            onclick={toggle}
        >
            <Trash2 class="h-3.5 w-3.5" />Trash
            <span class="section-count">{count}</span>
        </button>
        {#if open}
            {#if failed}
                <p class="mt-2 text-xs text-danger">The trash didn't load.</p>
            {:else if trashed === null}
                <p class="mt-2 flex items-center gap-2 text-xs text-fg-muted">
                    <Spinner size={12} />Loading
                </p>
            {:else if trashed.length === 0}
                <p class="mt-2 text-xs text-fg-muted">The trash is empty.</p>
            {:else}
                <ul class="mt-2 border-t border-line">
                    {#each trashed as document (document.id)}
                        {@const Icon = ICONS[document.file_type]}
                        <li
                            class="flex min-h-10 items-center gap-2.5 border-b border-line py-1.5"
                        >
                            <Icon class="h-4 w-4 shrink-0 text-fg-faint" />
                            <div class="min-w-0 flex-1">
                                {#if document.frozen}
                                    <a
                                        href="/workspace/documents/{document.id}"
                                        class="block truncate text-fg-muted hover:text-accent"
                                        >{document.title}</a
                                    >
                                {:else}
                                    <p class="truncate text-fg-muted">
                                        {document.title}
                                    </p>
                                {/if}
                                <p class="text-xs text-fg-faint">
                                    Moved to the trash {formatTimeAgo(
                                        document.deleted_at,
                                    )} · {plural(document.version, 'version')} kept{document.frozen
                                        ? ' · Read-only: its owner has left'
                                        : ''}
                                </p>
                            </div>
                            {#if document.can_delete}
                                <button
                                    type="button"
                                    class="btn-ghost shrink-0"
                                    onclick={() => restore(document)}
                                    ><RotateCcw
                                        class="h-3.5 w-3.5"
                                    />Restore</button
                                >
                            {/if}
                        </li>
                    {/each}
                </ul>
            {/if}
        {/if}
    </div>
{/if}
