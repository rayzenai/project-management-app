<script module lang="ts">
    export interface ProjectFiltersState {
        assigneeIds: number[];
        overdueOnly: boolean;
        category: string | null;
    }

    export interface CategoryOption {
        value: string;
        label: string;
        color: string | null;
    }
</script>

<script lang="ts">
    import { Check, ChevronDown } from '@lucide/svelte';
    import type { Member } from '../../lib/types';
    import Avatar from '../Avatar.svelte';
    import Popover from '../Popover.svelte';

    let {
        filters = $bindable(),
        teammates,
        categories,
        shownCount,
        totalCount,
    }: {
        filters: ProjectFiltersState;
        teammates: Member[];
        categories: CategoryOption[];
        shownCount: number;
        totalCount: number;
    } = $props();

    const anyActive = $derived(
        filters.assigneeIds.length > 0 ||
            filters.overdueOnly ||
            filters.category !== null,
    );

    const selected = $derived(
        teammates.filter((m) => filters.assigneeIds.includes(m.id)),
    );

    function toggleAssignee(id: number) {
        filters.assigneeIds = filters.assigneeIds.includes(id)
            ? filters.assigneeIds.filter((x) => x !== id)
            : [...filters.assigneeIds, id];
    }

    function clear() {
        filters = { assigneeIds: [], overdueOnly: false, category: null };
    }

    let assigneeOpen = $state(false);
</script>

<div class="ml-auto flex shrink-0 items-center gap-1.5">
    {#if anyActive}
        <span
            class={`font-mono text-xs tabular-nums ${shownCount === 0 ? 'text-danger' : 'text-fg-muted'}`}
        >
            {shownCount}/{totalCount}
        </span>
        <button type="button" class="btn-ghost" onclick={clear}>Clear</button>
        <span class="mx-0.5 h-4 w-px bg-line" aria-hidden="true"></span>
    {/if}

    {#if teammates.length > 0}
        <!-- One control instead of a row of unlabelled avatars: at eight people
             the old strip read as decoration rather than a filter. -->
        <Popover
            bind:open={assigneeOpen}
            align="right"
            role="listbox"
            triggerLabel="Filter by assignee"
            panelClass="w-60 max-h-72 overflow-auto"
        >
            {#snippet trigger()}
                <span
                    class={`btn ${selected.length > 0 ? 'border-accent/40 bg-accent-soft text-accent' : ''}`}
                >
                    {#if selected.length === 0}
                        Assignee
                    {:else}
                        <span class="flex -space-x-1">
                            {#each selected.slice(0, 3) as user (user.id)}
                                <Avatar
                                    name={user.name}
                                    class="ring-2 ring-surface"
                                />
                            {/each}
                        </span>
                        {#if selected.length > 3}
                            <span class="font-mono text-xs tabular-nums"
                                >+{selected.length - 3}</span
                            >
                        {/if}
                    {/if}
                    <ChevronDown class="h-3.5 w-3.5" />
                </span>
            {/snippet}

            {#each teammates as user (user.id)}
                {@const active = filters.assigneeIds.includes(user.id)}
                <button
                    type="button"
                    data-popover-item
                    role="option"
                    aria-selected={active}
                    class={`menu-item ${active ? 'bg-accent-soft text-fg' : ''}`}
                    onclick={() => toggleAssignee(user.id)}
                >
                    <Avatar name={user.name} size="md" />
                    <span class="min-w-0 flex-1 truncate text-left"
                        >{user.name}</span
                    >
                    {#if active}
                        <Check class="h-3.5 w-3.5 shrink-0 text-accent" />
                    {/if}
                </button>
            {/each}
        </Popover>
    {/if}

    <button
        type="button"
        aria-pressed={filters.overdueOnly}
        class={`btn ${
            filters.overdueOnly
                ? 'border-accent/40 bg-accent-soft text-accent hover:bg-accent-soft'
                : ''
        }`}
        onclick={() => (filters.overdueOnly = !filters.overdueOnly)}
    >
        Overdue
    </button>

    {#if categories.length > 0}
        <select
            aria-label="Category"
            value={filters.category ?? ''}
            onchange={(e) =>
                (filters.category =
                    (e.currentTarget as HTMLSelectElement).value || null)}
            class="input h-7 w-auto py-0"
        >
            <option value="">All categories</option>
            {#each categories as category (category.value)}
                <option value={category.value}>{category.label}</option>
            {/each}
        </select>
    {/if}
</div>
