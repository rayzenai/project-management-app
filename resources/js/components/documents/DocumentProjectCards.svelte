<script lang="ts">
    import { inertia } from '@inertiajs/svelte';
    import { FolderOpen } from '@lucide/svelte';
    import { formatTimeAgo, plural } from '../../lib/format';
    import type { DocumentProjectCard } from '../../lib/types';

    /**
     * The Documents page's Projects tab: a card per project whose documents
     * the viewer may use, each opening that project's Documents tab, where
     * its team's documents live.
     */
    let { cards }: { cards: DocumentProjectCard[] } = $props();
</script>

<ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
    {#each cards as card (card.slug)}
        <li>
            <a
                href={`/workspace/projects/${card.slug}?view=documents`}
                use:inertia
                class="flex min-h-[64px] items-center gap-3 rounded-lg border border-line bg-surface p-3 transition hover:border-accent hover:bg-hover"
            >
                <span
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-surface-alt text-fg-muted"
                    aria-hidden="true"><FolderOpen class="h-5 w-5" /></span
                >
                <span class="min-w-0">
                    <span class="block truncate font-medium text-fg"
                        >{card.label}</span
                    >
                    <span class="block truncate text-xs text-fg-muted">
                        {#if card.documents_count === 0}
                            No documents yet
                        {:else}
                            {plural(card.documents_count, 'document')} · {formatTimeAgo(
                                card.last_edited_at,
                            )}
                        {/if}
                    </span>
                </span>
            </a>
        </li>
    {/each}
</ul>
