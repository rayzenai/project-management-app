<script lang="ts">
    import { peopleCount } from '../../lib/structure';
    import type { OrgNode, OrgPerson } from '../../lib/structure';
    import Avatar from '../Avatar.svelte';

    /**
     * The organogram as a top-down chart: each person above the people who
     * report to them. A manager whose people have nobody under them stacks
     * them, so wide teams stay narrow. Used on screen and in the printout.
     */
    let { tree }: { tree: OrgNode[] } = $props();
</script>

{#snippet chartCard(person: OrgPerson, below: number, small: boolean)}
    <div
        class={`chart-card flex items-center gap-2 rounded-md border border-line bg-surface text-left ${small ? 'px-2 py-1' : 'px-2.5 py-1.5'}`}
    >
        <Avatar name={person.name} size={small ? 'sm' : 'md'} />
        <span class="flex min-w-0 flex-col">
            <span
                class={`truncate font-medium text-fg ${small ? 'text-[12.5px]' : 'text-[13px]'}`}
                >{person.name}</span
            >
            {#if person.designation || below > 0}
                <span
                    class={`truncate text-fg-muted ${small ? 'text-[11px]' : 'text-[11.5px]'}`}
                    >{[
                        person.designation,
                        below > 0 ? peopleCount(below) : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}</span
                >
            {/if}
        </span>
    </div>
{/snippet}

{#snippet chartBranch(node: OrgNode)}
    <li>
        {@render chartCard(node.person, node.below, false)}
        {#if node.children.length > 0}
            {#if node.children.every((child) => child.children.length === 0)}
                <div class="chart-stack">
                    {#each node.children as child (child.person.id)}
                        {@render chartCard(child.person, 0, true)}
                    {/each}
                </div>
            {:else}
                <ul>
                    {#each node.children as child (child.person.id)}
                        {@render chartBranch(child)}
                    {/each}
                </ul>
            {/if}
        {/if}
    </li>
{/snippet}

<div class="chart">
    <ul>
        {#each tree as root (root.person.id)}
            {@render chartBranch(root)}
        {/each}
    </ul>
</div>

<style>
    .chart {
        overflow-x: auto;
        padding-bottom: 8px;
    }
    .chart ul {
        position: relative;
        display: flex;
        justify-content: center;
        margin: 0;
        padding: 16px 0 0;
    }
    .chart > ul {
        padding-top: 0;
        width: max-content;
        min-width: 100%;
    }
    .chart li {
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 16px 6px 0;
        list-style: none;
    }
    .chart > ul > li,
    .chart li:only-child {
        padding-top: 0;
    }
    .chart li::before,
    .chart li::after {
        content: '';
        position: absolute;
        top: 0;
        right: 50%;
        width: 50%;
        height: 16px;
        border-top: 1px solid var(--ws-line);
    }
    .chart li::after {
        right: auto;
        left: 50%;
        border-left: 1px solid var(--ws-line);
    }
    .chart > ul > li::before,
    .chart > ul > li::after,
    .chart li:only-child::before,
    .chart li:only-child::after {
        display: none;
    }
    .chart li:first-child::before,
    .chart li:last-child::after {
        border: 0 none;
    }
    .chart li:last-child::before {
        border-right: 1px solid var(--ws-line);
        border-radius: 0 6px 0 0;
    }
    .chart li:first-child::after {
        border-radius: 6px 0 0 0;
    }
    .chart ul ul::before {
        content: '';
        position: absolute;
        top: 0;
        left: 50%;
        height: 16px;
        border-left: 1px solid var(--ws-line);
    }
    .chart :global(.chart-card) {
        max-width: 220px;
    }
    .chart-stack {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-top: 10px;
        padding-left: 12px;
        border-left: 1px solid var(--ws-line);
    }

    @media print {
        .chart {
            overflow: visible;
        }

        :global(.chart-card) {
            border-color: #bbb;
            background: #fff;
            color: #111;
            break-inside: avoid;
        }

        :global(.chart-card *) {
            color: #111;
        }

        .chart li::before,
        .chart li::after,
        .chart ul ul::before,
        .chart-stack {
            border-color: #999;
        }
    }
</style>
