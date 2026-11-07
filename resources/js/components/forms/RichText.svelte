<script lang="ts">
    import type { RichDoc, RichNode } from '../../lib/forms/types';

    /**
     * A text block's words, drawn from the editor's JSON (never as HTML):
     * paragraphs, lists, line breaks, bold, italic and links, the only
     * things App\Support\Forms\FormRichText keeps.
     */
    let { doc }: { doc: RichDoc | null | undefined } = $props();

    function has(node: RichNode, mark: string): boolean {
        return (node.marks ?? []).some((m) => m.type === mark);
    }

    function href(node: RichNode): string | null {
        const link = (node.marks ?? []).find((m) => m.type === 'link');
        const url = link?.attrs?.href ?? '';

        return /^(https?:|mailto:)/i.test(url) ? url : null;
    }
</script>

{#snippet inline(node: RichNode)}
    {#if node.type === 'hardBreak'}
        <br />
    {:else if node.type === 'text'}
        {@const url = href(node)}
        {#if url}
            <a
                href={url}
                target="_blank"
                rel="noopener noreferrer"
                class="text-accent underline underline-offset-2"
                >{@render styled(node)}</a
            >
        {:else}
            {@render styled(node)}
        {/if}
    {/if}
{/snippet}

{#snippet styled(node: RichNode)}
    {#if has(node, 'bold') && has(node, 'italic')}
        <strong><em>{node.text}</em></strong>
    {:else if has(node, 'bold')}
        <strong>{node.text}</strong>
    {:else if has(node, 'italic')}
        <em>{node.text}</em>
    {:else}
        {node.text}
    {/if}
{/snippet}

{#snippet blockNode(node: RichNode)}
    {#if node.type === 'paragraph'}
        <p>
            {#each node.content ?? [] as child, i (i)}{@render inline(
                    child,
                )}{/each}
        </p>
    {:else if node.type === 'bulletList'}
        <ul class="list-disc pl-5">
            {#each node.content ?? [] as child, i (i)}{@render blockNode(
                    child,
                )}{/each}
        </ul>
    {:else if node.type === 'orderedList'}
        <ol class="list-decimal pl-5">
            {#each node.content ?? [] as child, i (i)}{@render blockNode(
                    child,
                )}{/each}
        </ol>
    {:else if node.type === 'listItem'}
        <li>
            {#each node.content ?? [] as child, i (i)}{@render blockNode(
                    child,
                )}{/each}
        </li>
    {/if}
{/snippet}

{#if doc}
    <div class="flex flex-col gap-2 text-[14px] leading-relaxed text-fg">
        {#each doc.content ?? [] as node, i (i)}{@render blockNode(node)}{/each}
    </div>
{/if}
